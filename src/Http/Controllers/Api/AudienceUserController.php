<?php

namespace JanDev\EmailSystem\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\BouncedEmail;
use JanDev\EmailSystem\Models\EmailAudienceGroup;

class AudienceUserController extends Controller
{
    /**
     * GET /api/v1/audience-groups/{group}/subscribers
     * List subscribers with pagination, filtering, and search.
     */
    public function index(Request $request, int $group)
    {
        $audienceGroup = EmailAudienceGroup::find($group);
        if (!$audienceGroup) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $query = AudienceUser::where('email_audience_group_id', $group);

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->input('is_active'));
        }

        if ($request->has('bounced')) {
            $query->where('bounced', (bool) $request->input('bounced'));
        }

        if ($request->filled('email')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->input('email'));
            $query->where('email', 'like', '%' . $search . '%');
        }

        $paginated = $query->orderBy('id')->paginate(50);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/audience-groups/{group}/subscribers
     * Add a single subscriber (Observer handles bounce cross-referencing).
     */
    public function store(Request $request, int $group)
    {
        $audienceGroup = EmailAudienceGroup::find($group);
        if (!$audienceGroup) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'email'         => 'required|email|max:255',
            'name'          => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
        ]);

        $email = strtolower(trim($validated['email']));

        // Upsert by (email, group_id)
        $existing = AudienceUser::where('email_audience_group_id', $group)
            ->where('email', $email)
            ->first();

        if ($existing) {
            $existing->update([
                'name'          => $validated['name'] ?? $existing->name,
                'custom_fields' => $validated['custom_fields'] ?? $existing->custom_fields,
            ]);
            return response()->json(['data' => $existing->fresh()], 200);
        }

        $user = AudienceUser::create([
            'email'                  => $email,
            'name'                   => $validated['name'] ?? null,
            'custom_fields'          => $validated['custom_fields'] ?? [],
            'email_audience_group_id' => $group,
            'is_active'              => true,
            'bounced'                => false,
            'unsubscribe_token'      => Str::random(32),
        ]);

        return response()->json(['data' => $user->fresh()], 201);
    }

    /**
     * PUT /api/v1/audience-groups/{group}/subscribers/{id}
     * Update subscriber fields.
     */
    public function update(Request $request, int $group, int $id)
    {
        $user = AudienceUser::where('email_audience_group_id', $group)->find($id);
        if (!$user) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name'          => 'nullable|string|max:255',
            'is_active'     => 'nullable|boolean',
            'custom_fields' => 'nullable|array',
        ]);

        $user->update($validated);

        return response()->json(['data' => $user->fresh()]);
    }

    /**
     * DELETE /api/v1/audience-groups/{group}/subscribers/{id}
     */
    public function destroy(int $group, int $id)
    {
        $user = AudienceUser::where('email_audience_group_id', $group)->find($id);
        if (!$user) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/audience-groups/{group}/subscribers/batch
     * Batch import up to 1000 subscribers.
     * Upserts by (email, group_id). All-or-nothing transaction.
     * Pre-loads bounced emails to avoid N+1 queries.
     */
    public function batch(Request $request, int $group)
    {
        $audienceGroup = EmailAudienceGroup::find($group);
        if (!$audienceGroup) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'subscribers'              => 'required|array|max:1000',
            'subscribers.*.email'      => 'required|email|max:255',
            'subscribers.*.name'       => 'nullable|string|max:255',
            'subscribers.*.custom_fields' => 'nullable|array',
        ]);

        $subscribers = $validated['subscribers'];
        $emails = array_map(fn ($s) => strtolower(trim($s['email'])), $subscribers);

        // Pre-load bounced emails in one query
        $bouncedMap = BouncedEmail::whereIn('email', $emails)
            ->pluck('bounce_type', 'email')
            ->toArray();

        // Pre-load existing subscribers to distinguish insert vs update
        $existingMap = AudienceUser::where('email_audience_group_id', $group)
            ->whereIn('email', $emails)
            ->pluck('id', 'email')
            ->toArray();

        $inserted = 0;
        $updated = 0;
        $bounced = 0;
        $now = now()->toDateTimeString();

        DB::transaction(function () use (
            $subscribers, $emails, $group, $bouncedMap, $existingMap,
            &$inserted, &$updated, &$bounced, $now
        ) {
            foreach ($subscribers as $sub) {
                $email = strtolower(trim($sub['email']));
                $isBounced = isset($bouncedMap[$email]);
                $bounceType = $bouncedMap[$email] ?? null;

                $row = [
                    'email'                   => $email,
                    'name'                    => $sub['name'] ?? null,
                    'custom_fields'           => isset($sub['custom_fields']) ? json_encode($sub['custom_fields']) : null,
                    'email_audience_group_id' => $group,
                    'is_active'               => !$isBounced,
                    'bounced'                 => $isBounced,
                    'bounce_type'             => $bounceType,
                    'bounced_at'              => $isBounced ? $now : null,
                    'updated_at'              => $now,
                ];

                // Use DB::table() directly to bypass Eloquent observers (no N+1 bounce checks)
                if (isset($existingMap[$email])) {
                    DB::table('audience_users')->where('id', $existingMap[$email])->update($row);
                    $updated++;
                } else {
                    $row['created_at'] = $now;
                    $row['unsubscribe_token'] = \Illuminate\Support\Str::random(32);
                    DB::table('audience_users')->insert($row);
                    $inserted++;
                }

                if ($isBounced) {
                    $bounced++;
                }
            }
        });

        return response()->json([
            'inserted' => $inserted,
            'updated'  => $updated,
            'bounced'  => $bounced,
        ]);
    }
}
