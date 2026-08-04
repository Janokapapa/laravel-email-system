<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\SmsSuppression;
use JanDev\EmailSystem\Support\Sms\Mobivate;
use JanDev\EmailSystem\Support\Sms\ShortLinkClient;
use JanDev\EmailSystem\Support\Sms\SmsCampaignSender;
use JanDev\EmailSystem\Support\Sms\SmsPhone;
use JanDev\EmailSystem\Support\Sms\SmsConfig;
use JanDev\EmailSystem\Support\Sms\SmsPreflight;
use JanDev\EmailSystem\Support\Sms\SmsPricing;
use JanDev\EmailSystem\Support\Sms\SmsText;

/**
 * Send an SMS campaign to a handful of numbers.
 *
 * The point of a test send is that what arrives on the phone is what will arrive
 * on everyone's, so this goes through the same composition and the same
 * per-recipient link minting as a real send. A test that took a shortcut would
 * prove nothing about the campaign it is testing.
 *
 * The numbers replace the audience entirely rather than adding to it: "test plus
 * everyone" is how a test reaches the whole list.
 */
class SmsTest extends Command
{
    protected $signature = 'email:sms-test
        {campaign? : campaign id (omit with --preflight to check the chain only)}
        {numbers? : comma-separated, international format; defaults to SMS_TEST_NUMBERS}
        {--dry : show what would be sent without sending}
        {--preflight : check the whole chain (provider, pricing, caps, links, delivery reports) and stop}
        {--wait-dr=0 : after sending, wait this many seconds for delivery reports and report what arrived}';

    protected $description = 'Send an SMS campaign to a few test numbers';

    public function handle(): int
    {
        $campaignId = $this->argument('campaign');
        $campaign = $campaignId !== null ? Campaign::find((int) $campaignId) : null;

        // --preflight answers "would a send work at all", which is worth knowing
        // before there is a campaign to send.
        if ($this->option('preflight')) {
            return $this->preflight($campaign);
        }

        if (!$campaign) {
            $this->error('Campaign not found.');

            return self::FAILURE;
        }
        if (!$campaign->isSms()) {
            $this->error('That is an e-mail campaign. Duplicate it as SMS instead.');

            return self::FAILURE;
        }

        // A saved test list means the same phones every time, which is what makes
        // two test sends comparable.
        $raw = (string) ($this->argument('numbers') ?? '');
        $fromConfig = false;
        if (trim($raw) === '') {
            $raw = (string) SmsConfig::get('email-system.sms.test_numbers', '');
            $fromConfig = trim($raw) !== '';
        }

        $numbers = SmsPhone::parseList($raw);
        if ($numbers === []) {
            $this->error('No usable numbers. Pass them as an argument or set SMS_TEST_NUMBERS.');
            $this->line('Example: +447700900123,+36301234567');

            return self::FAILURE;
        }
        if ($fromConfig) {
            $this->comment('Using the saved test list (SMS_TEST_NUMBERS).');
        }

        $body = (string) $campaign->body;
        $measured = SmsText::previewShortenedLinks($body, ShortLinkClient::sampleUrl());
        if (SmsCampaignSender::foldEnabled()) {
            $measured = SmsText::foldToGsm7($measured);
        }

        $this->line('');
        $this->line('Campaign: ' . $campaign->name);
        $this->line('Encoding: ' . SmsText::encodingOf($measured) . '  Segments: ' . SmsText::segments($measured));

        $total = 0.0;
        foreach ($numbers as $number) {
            $price = SmsPricing::forPhone($number);
            $cost = $price === null ? null : $price * SmsText::segments($measured);
            $total += $cost ?? 0.0;
            $this->line(sprintf(
                '  %-18s %-12s %s',
                $number,
                Mobivate::originatorFor($number),
                $cost === null ? 'no price' : number_format($cost, 4) . ' EUR'
            ));

            // A suppressed number is a person who asked us to stop. Saying so here
            // is the difference between "the test did not arrive" and knowing why.
            if (SmsSuppression::isBlocked($number)) {
                $this->warn('    ^ this number has opted out; a real send would skip it');
            }
        }
        $this->line('  Total: ' . number_format($total, 4) . ' EUR');
        $this->line('');

        if ($this->option('dry')) {
            $this->comment('Dry run, nothing sent.');

            return self::SUCCESS;
        }

        $blocked = SmsCampaignSender::blockedReason($campaign);
        if ($blocked !== null) {
            $this->error($blocked);

            return self::FAILURE;
        }

        if (!$this->confirm('Send to ' . count($numbers) . ' number(s)?', false)) {
            return self::SUCCESS;
        }

        $sentAt = now();
        $result = SmsCampaignSender::send($campaign, $numbers);

        $this->info(sprintf('Accepted by the provider: %d, failed: %d, skipped: %d', $result['sent'], $result['failed'], $result['skipped']));

        $wait = (int) $this->option('wait-dr');
        if ($wait > 0) {
            return $this->waitForDeliveryReports($campaign, $sentAt, $wait);
        }

        $this->comment('Accepted is not delivered. Re-run with --wait-dr=60 to see what actually reached a handset.');

        return self::SUCCESS;
    }

    /**
     * Check the whole chain and print it as a report.
     *
     * Ordered worst-first is deliberate: the operator reads the top of the list,
     * and a FAIL buried under six OK lines is a FAIL nobody acts on.
     */
    private function preflight(?Campaign $campaign): int
    {
        $summary = SmsPreflight::run($campaign);

        $this->line('');
        $this->line('SMS preflight' . ($campaign ? ' — campaign: ' . $campaign->name : ''));
        $this->line(str_repeat('-', 60));

        $order = [SmsPreflight::FAIL => 0, SmsPreflight::WARN => 1, SmsPreflight::OK => 2];
        $checks = $summary['checks'];
        usort($checks, fn ($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        foreach ($checks as $c) {
            $label = sprintf('  %-18s', $c['name']);
            $line = $label . $c['detail'];
            match ($c['severity']) {
                SmsPreflight::FAIL => $this->error('FAIL' . $line),
                SmsPreflight::WARN => $this->warn('WARN' . $line),
                default => $this->info('OK  ' . $line),
            };
        }

        $this->line(str_repeat('-', 60));
        if ($summary['sendable']) {
            $this->info(sprintf('Sendable. %d warning(s).', $summary['warnings']));

            return self::SUCCESS;
        }

        $this->error(sprintf('NOT sendable: %d blocking problem(s).', $summary['fails']));

        return self::FAILURE;
    }

    /**
     * Close the loop: did anything actually arrive?
     *
     * Only meaningful once the provider's DR webhook points at
     * /email-system/sms/dr — without it these rows stay 'sent' forever, which is
     * exactly what the wait reveals.
     */
    private function waitForDeliveryReports(Campaign $campaign, $sentAt, int $seconds): int
    {
        $this->line('');
        $this->line("Waiting up to {$seconds}s for delivery reports...");

        $deadline = time() + $seconds;
        $rows = collect();

        while (time() < $deadline) {
            $rows = EmailLog::where('campaign_id', $campaign->id)
                ->where('channel', 'sms')
                ->where('sent_at', '>=', $sentAt)
                ->get(['recipient', 'status', 'provider_message_id', 'error']);

            $pending = $rows->whereNotIn('status', ['delivered', 'undelivered'])->count();
            if ($rows->isNotEmpty() && $pending === 0) {
                break;
            }
            sleep(3);
        }

        $this->line('');
        foreach ($rows as $r) {
            $line = sprintf('  %-18s %-12s %s', $r->recipient, $r->status, $r->error ?? '');
            $r->status === 'delivered' ? $this->info($line) : $this->warn($line);
        }

        $delivered = $rows->where('status', 'delivered')->count();
        $unknown = $rows->whereNotIn('status', ['delivered', 'undelivered'])->count();

        $this->line('');
        $this->info("Delivered: {$delivered} / " . $rows->count());
        if ($unknown > 0) {
            $this->warn("{$unknown} still without a report — if this never changes, the provider's DR webhook is not pointed at /email-system/sms/dr.");
        }

        return self::SUCCESS;
    }
}
