<?php

namespace JanDev\EmailSystem\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\SmsSuppression;
use JanDev\EmailSystem\Support\Sms\SmsPhone;

/**
 * Inbound SMS from the provider: opt-outs and delivery reports.
 *
 * Opt-out is the only thing a recipient can do to stop marketing SMS, so it has
 * to work on the first attempt and forever after. The keyword list is generous on
 * purpose: someone typing "stop please" or "unsubscribe" means the same thing as
 * "STOP", and a strict match would keep texting a person who plainly asked us not
 * to.
 *
 * Both endpoints always answer 200. A provider that gets an error retries, and a
 * retried STOP is not a problem, but a provider that gets errors repeatedly can
 * disable the callback entirely - which would lose opt-outs silently.
 */
class SmsInboundController extends Controller
{
    /** Words that mean "stop", in the languages this list is sent to. */
    private const STOP_WORDS = [
        'stop', 'stopall', 'unsubscribe', 'unsub', 'end', 'quit', 'cancel', 'optout', 'opt-out',
        'leave', 'remove', 'nem', 'nie', 'stopp', 'alto', 'arret', 'arrêt', 'baja', 'sluta',
    ];

    /**
     * Mobile-originated message. A STOP here suppresses the number for good.
     */
    public function mo(Request $request): JsonResponse
    {
        $from = (string) ($request->input('originator') ?? $request->input('from') ?? $request->input('msisdn') ?? '');
        $body = (string) ($request->input('body') ?? $request->input('message') ?? $request->input('text') ?? '');

        // A leading plus is often dropped in transit; without it the number is not
        // E.164 and would fail to normalise, losing the opt-out.
        if ($from !== '' && !str_starts_with($from, '+')) {
            $from = '+' . ltrim($from, '+');
        }

        if (SmsPhone::normalise($from) === null) {
            Log::warning('SMS inbound: unusable sender', ['from' => $request->input('originator')]);

            return response()->json(['ok' => true]);
        }

        if (self::isStop($body)) {
            SmsSuppression::block($from, 'stop', 'inbound');
            Log::info('SMS opt-out recorded', ['phone' => $from]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Delivery report. Accepted by the provider is not the same as delivered to a
     * handset, and without this callback the difference is invisible: a filtered
     * message looks exactly like a delivered one, and is billed the same.
     */
    public function dr(Request $request): JsonResponse
    {
        Log::info('SMS delivery report', $request->all());

        return response()->json(['ok' => true]);
    }

    /**
     * Whether a message body is an opt-out.
     *
     * Matched on the first word as well as the whole body, so "STOP" inside a
     * longer sentence still counts but an ordinary reply mentioning the word in
     * passing does not silently unsubscribe someone.
     */
    public static function isStop(string $body): bool
    {
        $clean = strtolower(trim(preg_replace('/[^\p{L}\s-]+/u', '', $body) ?? ''));
        if ($clean === '') {
            return false;
        }

        if (in_array($clean, self::STOP_WORDS, true)) {
            return true;
        }

        $first = strtok($clean, " \t\n");

        return $first !== false && in_array($first, self::STOP_WORDS, true);
    }
}
