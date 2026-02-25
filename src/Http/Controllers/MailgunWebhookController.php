<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Jobs\ProcessMailgunWebhook;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MailgunWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $eventData = $request->input('event-data', []);
        $event = $eventData['event'] ?? $request->input('event');

        if (!$event) {
            return response()->json(['error' => 'No event type'], 400);
        }

        $processEvents = ['delivered', 'failed', 'bounced', 'complained', 'unsubscribed', 'opened', 'clicked'];

        if (!in_array($event, $processEvents)) {
            return response()->json(['status' => 'ok']);
        }

        $minimalData = [
            'event' => $event,
            'recipient' => $eventData['recipient'] ?? $request->input('recipient'),
            'message_id' => $eventData['message']['headers']['message-id'] ?? null,
            'severity' => $eventData['severity'] ?? null,
            'delivery_status' => $eventData['delivery-status'] ?? [],
        ];

        ProcessMailgunWebhook::dispatch($minimalData);

        return response()->json(['status' => 'queued']);
    }

    private function verifySignature(Request $request): bool
    {
        $signingKey = config('email-system.mailgun.webhook_signing_key');

        if (empty($signingKey)) {
            return true;
        }

        $signature = $request->input('signature', []);
        $timestamp = $signature['timestamp'] ?? $request->input('timestamp');
        $token = $signature['token'] ?? $request->input('token');
        $receivedSignature = $signature['signature'] ?? $request->input('signature');

        if (!$timestamp || !$token || !$receivedSignature) {
            return false;
        }

        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . $token, $signingKey);

        return hash_equals($expectedSignature, $receivedSignature);
    }
}
