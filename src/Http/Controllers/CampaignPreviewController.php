<?php

namespace JanDev\EmailSystem\Http\Controllers;

use JanDev\EmailSystem\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CampaignPreviewController
{
    public function preview(Request $request, int $id): Response
    {
        $campaign = Campaign::findOrFail($id);

        $html = $campaign->body ?? '<p><em>(No content)</em></p>';

        $content = view('email-system::campaign-preview', [
            'htmlContent' => $html,
        ])->render();

        return response($content, 200)->header('Content-Type', 'text/html');
    }
}
