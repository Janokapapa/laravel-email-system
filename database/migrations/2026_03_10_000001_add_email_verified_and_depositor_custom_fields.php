<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use JanDev\UserManagement\Models\Setting;

return new class extends Migration
{
    // DDL (ALTER TABLE) causes implicit commit — disable transaction wrapping
    public $withinTransaction = false;

    public function up(): void
    {
        // Read current custom field definitions
        $current = Setting::get('audience', 'custom_fields', []);
        if (!is_array($current)) {
            $current = [];
        }

        // Extract existing slugs to avoid duplicates
        $existingSlugs = array_column($current, 'slug');

        $toAdd = [];

        if (!in_array('email_verified', $existingSlugs)) {
            $toAdd[] = [
                'name'       => 'Email Verified',
                'slug'       => 'email_verified',
                'type'       => 'boolean',
                'required'   => false,
                'sort_order' => 3,
            ];
        }

        if (!in_array('depositor', $existingSlugs)) {
            $toAdd[] = [
                'name'       => 'Depositor',
                'slug'       => 'depositor',
                'type'       => 'boolean',
                'required'   => false,
                'sort_order' => 4,
            ];
        }

        if (empty($toAdd)) {
            return;
        }

        $updated = array_merge($current, $toAdd);

        // Setting::set() auto-clears the cache
        Setting::set('audience', 'custom_fields', $updated);

        // Sync virtual generated columns + indexes for new slugs
        Artisan::call('audience:sync-custom-field-indexes');
    }

    public function down(): void
    {
        $current = Setting::get('audience', 'custom_fields', []);
        if (!is_array($current)) {
            return;
        }

        $filtered = array_values(array_filter(
            $current,
            fn ($f) => !in_array($f['slug'] ?? '', ['email_verified', 'depositor'])
        ));

        Setting::set('audience', 'custom_fields', $filtered);

        // Re-sync to drop the orphaned virtual columns
        Artisan::call('audience:sync-custom-field-indexes');
    }
};
