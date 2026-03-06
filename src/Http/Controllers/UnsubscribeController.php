<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use function JanDev\EmailSystem\resolve_callback;

class UnsubscribeController extends Controller
{
    public function unsubscribe(Request $request)
    {
        $this->setLocaleFromBrowser($request);

        $email = $request->query('email');
        $token = $request->query('token');

        if (!$email || !$token) {
            return view('email-system::unsubscribe', [
                'success' => false,
                'message' => __('Invalid unsubscribe link.'),
            ]);
        }

        $audienceUser = AudienceUser::where('email', $email)
            ->where('unsubscribe_token', $token)
            ->where('is_active', true)
            ->first();

        if (!$audienceUser) {
            return view('email-system::unsubscribe', [
                'success' => false,
                'message' => __('Invalid or expired unsubscribe link.'),
            ]);
        }

        // Mark the specific email_log as unsubscribed (campaign tracking)
        $logId = $request->query('log_id');
        if ($logId) {
            $emailLog = EmailLog::find($logId);
            if ($emailLog && $emailLog->recipient === $email) {
                $emailLog->markAsUnsubscribed();
            }
        }

        // Unsubscribe all audience entries for this email
        AudienceUser::where('email', $email)->update([
            'is_active' => false,
            'unsubscribe_token' => null,
        ]);

        // Call custom unsubscribe handler if configured
        $handler = resolve_callback(config('email-system.unsubscribe_handler'));
        if ($handler) {
            $handler($email);
        }

        return view('email-system::unsubscribe', [
            'success' => true,
            'message' => __('You have been successfully unsubscribed from our newsletter.'),
        ]);
    }

    protected function setLocaleFromBrowser(Request $request): void
    {
        $supported = config('email-system.unsubscribe_locales', [
            'en', 'de', 'fr', 'es', 'it', 'nl', 'sv', 'da', 'fi', 'no',
            'pt', 'pl', 'cs', 'ro', 'el', 'hu',
        ]);
        $header = $request->header('Accept-Language', '');

        // Parse Accept-Language: "en-GB,en;q=0.9,de;q=0.8" → ['en', 'de']
        $preferred = null;
        $maxQ = -1;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $segments = explode(';', $part);
            $lang = strtolower(trim($segments[0]));
            $q = 1.0;

            if (isset($segments[1]) && preg_match('/q=([\d.]+)/', $segments[1], $m)) {
                $q = (float) $m[1];
            }

            // Extract primary language code (en-GB → en)
            $primary = explode('-', $lang)[0];

            if ($q > $maxQ && in_array($primary, $supported)) {
                $maxQ = $q;
                $preferred = $primary;
            }
        }

        if ($preferred) {
            app()->setLocale($preferred);
        }
    }
}
