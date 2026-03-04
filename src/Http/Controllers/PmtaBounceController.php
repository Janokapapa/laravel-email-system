<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\BouncedEmail;
use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use function JanDev\EmailSystem\resolve_callback;

class PmtaBounceController extends Controller
{
    /**
     * Handle PMTA hard bounce notification.
     *
     * Called by /var/www/pmta/scripts/process-bounces.py for every STOP (hard) bounce.
     * Payload: {"email": "bounced@example.com"}
     * Auth: X-API-Key header must match config('email-system.pmta.bounce_api_key')
     * Response: {"result": "OK", "found": true|false}
     */
    public function handle(Request $request)
    {
        // Authenticate
        $expectedKey = config('email-system.pmta.bounce_api_key');
        $receivedKey = $request->header('X-API-Key');

        if (empty($expectedKey) || !is_string($receivedKey) || !hash_equals($expectedKey, $receivedKey)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Validate
        $email = strtolower(trim($request->input('email', '')));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Invalid or missing email'], 400);
        }

        // Save to global bounce registry (regardless of whether email exists in audience lists)
        BouncedEmail::updateOrCreate(
            ['email' => $email],
            [
                'bounce_type' => 'hard',
                'bounce_reason' => 'PMTA hard bounce',
                'source' => 'pmta',
                'bounced_at' => now(),
            ]
        );

        // Mark ALL AudienceUser records with this email as hard-bounced and inactive
        $affectedRows = AudienceUser::where('email', $email)->update([
            'bounced' => true,
            'bounce_type' => 'hard',
            'bounce_reason' => 'PMTA hard bounce',
            'bounced_at' => now(),
            'is_active' => false,
        ]);
        $found = $affectedRows > 0;

        if ($found) {
            // Mark latest non-failed EmailLog for this recipient
            $log = EmailLog::where('recipient', $email)
                ->where('status', '!=', 'failed')
                ->orderByDesc('created_at')
                ->first();

            if ($log) {
                $log->update([
                    'status' => 'failed',
                    'bounce_type' => 'hard',
                    'bounce_reason' => 'PMTA hard bounce',
                    'bounced_at' => now(),
                ]);
            }

            // Call configured bounce handler callback
            try {
                $bounceHandler = resolve_callback(config('email-system.bounce_handler'));
                if ($bounceHandler) {
                    $bounceHandler($email, 'PMTA hard bounce');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['result' => 'OK', 'found' => $found]);
    }
}
