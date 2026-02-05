<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Models\AudienceUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UnsubscribeController extends Controller
{
    public function unsubscribe(Request $request)
    {
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

        // Unsubscribe all audience entries for this email
        AudienceUser::where('email', $email)->update([
            'is_active' => false,
            'unsubscribe_token' => null,
        ]);

        // Call custom unsubscribe handler if configured
        $handler = config('email-system.unsubscribe_handler');
        if (is_callable($handler)) {
            $handler($email);
        }

        return view('email-system::unsubscribe', [
            'success' => true,
            'message' => __('You have been successfully unsubscribed from our newsletter.'),
        ]);
    }
}
