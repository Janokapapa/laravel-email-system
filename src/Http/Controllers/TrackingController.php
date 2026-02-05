<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TrackingController extends Controller
{
    public function trackOpen(Request $request, int $logId)
    {
        if (!$request->hasValidSignature()) {
            abort(403);
        }

        $emailLog = EmailLog::find($logId);

        if ($emailLog && !$emailLog->opened) {
            $emailLog->markAsOpened();
        }

        // Return 1x1 transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
