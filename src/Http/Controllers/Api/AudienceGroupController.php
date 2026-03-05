<?php

namespace JanDev\EmailSystem\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use JanDev\EmailSystem\Models\EmailAudienceGroup;

class AudienceGroupController extends Controller
{
    /**
     * GET /api/v1/audience-groups
     * List all groups with subscriber counts (single aggregated query).
     */
    public function index()
    {
        $groups = EmailAudienceGroup::select('email_audience_groups.*')
            ->selectSub(
                \JanDev\EmailSystem\Models\AudienceUser::selectRaw('COUNT(*)')
                    ->whereColumn('email_audience_group_id', 'email_audience_groups.id'),
                'subscriber_count'
            )
            ->orderBy('name')
            ->get()
            ->map(fn ($g) => [
                'id'               => $g->id,
                'name'             => $g->name,
                'subscriber_count' => (int) $g->subscriber_count,
                'created_at'       => $g->created_at,
                'updated_at'       => $g->updated_at,
            ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * POST /api/v1/audience-groups
     * Create a new group.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $group = EmailAudienceGroup::create($validated);

        return response()->json(['data' => [
            'id'               => $group->id,
            'name'             => $group->name,
            'subscriber_count' => 0,
            'created_at'       => $group->created_at,
            'updated_at'       => $group->updated_at,
        ]], 201);
    }

    /**
     * GET /api/v1/audience-groups/{id}
     * Show group details with stats.
     */
    public function show(int $id)
    {
        $group = EmailAudienceGroup::find($id);

        if (!$group) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $stats = \JanDev\EmailSystem\Models\AudienceUser::where('email_audience_group_id', $id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN bounced = 1 THEN 1 ELSE 0 END) as bounced
            ')
            ->first();

        return response()->json(['data' => [
            'id'               => $group->id,
            'name'             => $group->name,
            'subscriber_count' => (int) $stats->total,
            'active_count'     => (int) $stats->active,
            'inactive_count'   => (int) $stats->inactive,
            'bounced_count'    => (int) $stats->bounced,
            'created_at'       => $group->created_at,
            'updated_at'       => $group->updated_at,
        ]]);
    }

    /**
     * DELETE /api/v1/audience-groups/{id}
     * Delete group (cascades to subscribers).
     * Requires ?confirm=true if group has subscribers.
     */
    public function destroy(Request $request, int $id)
    {
        $group = EmailAudienceGroup::find($id);

        if (!$group) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $subscriberCount = \JanDev\EmailSystem\Models\AudienceUser::where('email_audience_group_id', $id)->count();

        if ($subscriberCount > 0 && $request->query('confirm') !== 'true') {
            return response()->json([
                'error'            => 'Group has subscribers. Add ?confirm=true to confirm deletion.',
                'subscriber_count' => $subscriberCount,
            ], 422);
        }

        // Delete subscribers first, then group
        \JanDev\EmailSystem\Models\AudienceUser::where('email_audience_group_id', $id)->delete();
        $group->delete();

        return response()->json([
            'deleted_subscriber_count' => $subscriberCount,
        ], 200);
    }
}
