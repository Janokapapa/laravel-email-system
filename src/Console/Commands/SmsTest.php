<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\SmsSuppression;
use JanDev\EmailSystem\Support\Sms\Mobivate;
use JanDev\EmailSystem\Support\Sms\ShortLinkClient;
use JanDev\EmailSystem\Support\Sms\SmsCampaignSender;
use JanDev\EmailSystem\Support\Sms\SmsPhone;
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
        {campaign : campaign id}
        {numbers : comma-separated, international format}
        {--dry : show what would be sent without sending}';

    protected $description = 'Send an SMS campaign to a few test numbers';

    public function handle(): int
    {
        $campaign = Campaign::find((int) $this->argument('campaign'));
        if (!$campaign) {
            $this->error('Campaign not found.');

            return self::FAILURE;
        }
        if (!$campaign->isSms()) {
            $this->error('That is an e-mail campaign. Duplicate it as SMS instead.');

            return self::FAILURE;
        }

        $numbers = SmsPhone::parseList((string) $this->argument('numbers'));
        if ($numbers === []) {
            $this->error('No usable numbers. Use international format, e.g. +447700900123.');

            return self::FAILURE;
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

        $result = SmsCampaignSender::send($campaign, $numbers);

        $this->info(sprintf('Accepted by the provider: %d, failed: %d, skipped: %d', $result['sent'], $result['failed'], $result['skipped']));
        $this->comment('Accepted is not delivered. Without a delivery report we cannot tell whether it reached a handset.');

        return self::SUCCESS;
    }
}
