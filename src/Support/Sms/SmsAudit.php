<?php

namespace JanDev\EmailSystem\Support\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Structured trail of everything an SMS campaign does.
 *
 * Written for the case where somebody else ran the campaign and we reconstruct
 * afterwards what happened: which audience was selected and why it shrank, what
 * the message cost and why, what the provider said per batch, and where a run
 * stopped. Every line is one JSON object, so a whole campaign can be replayed
 * from the log instead of inferred from row counts.
 *
 * Ported from casino_common's common/service/SmsAudit.php, so a campaign on
 * either side of the fleet reads the same way.
 */
final class SmsAudit
{
    /** Event names are greppable identifiers — renaming one breaks saved queries. */
    public const EVENT_AUDIENCE = 'audience';
    public const EVENT_ESTIMATE = 'estimate';
    public const EVENT_BATCH    = 'batch';
    public const EVENT_STOPPED  = 'stopped';
    public const EVENT_FINISHED = 'finished';

    /**
     * Build one audit entry. The identifying fields are applied LAST so a
     * payload cannot disguise one kind of entry as another — the trail is the
     * evidence, and it has to be trustworthy when read months later.
     *
     * Floats are rounded so money reads as money rather than as binary noise.
     */
    public static function entry(string $event, ?int $campaignId, array $payload = []): array
    {
        $clean = [];
        foreach ($payload as $k => $v) {
            $clean[$k] = is_float($v) ? round($v, 4) : $v;
        }

        return array_merge($clean, [
            'event' => $event,
            'campaign_id' => $campaignId,
        ]);
    }

    /**
     * Write one entry. Never throws: an audit failure must not take a paid
     * campaign down mid-run.
     */
    public static function log(string $event, ?int $campaignId, array $payload = []): void
    {
        try {
            Log::channel(self::channel())->info('sms-audit', self::entry($event, $campaignId, $payload));
        } catch (\Throwable $e) {
            // Best effort: fall back to the default logger, then give up.
            try {
                Log::info('sms-audit', self::entry($event, $campaignId, $payload));
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * Dedicated channel when configured, otherwise the app default — a missing
     * channel must not silence the trail.
     */
    private static function channel(): string
    {
        $channel = (string) SmsConfig::get('email-system.sms.audit_channel', '');

        return $channel !== '' ? $channel : (string) config('logging.default', 'stack');
    }
}
