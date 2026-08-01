<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AudienceUser extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'is_active',
        'email_audience_group_id',
        'unsubscribe_token',
        'bounced',
        'bounce_type',
        'bounce_reason',
        'bounced_at',
        'sent_at',
        'custom_fields',
        'zerobounce_status',
        'zerobounce_sub_status',
        'zerobounce_checked_at',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'bounced'     => 'boolean',
        'bounced_at'  => 'datetime',
        'sent_at'     => 'datetime',
        'custom_fields'          => 'array',
        'zerobounce_checked_at'  => 'datetime',
    ];

    /**
     * Ensure custom_fields always returns an array, never null.
     */
    public function getCustomFieldsAttribute(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a single custom field value by slug.
     */
    public function getCustomFieldValue(string $slug): mixed
    {
        return $this->custom_fields[$slug] ?? null;
    }

    /**
     * Replace {{placeholder}} tokens in a string with this user's data.
     * Supports {{name}}, {{email}}, and any custom field slug.
     */
    public function resolvePlaceholders(string $text): string
    {
        $map = [
            '{{name}}'  => $this->name,
            '{{email}}' => $this->email,
        ];

        // Add all defined custom field slugs (empty string for missing values)
        $definitions = static::getCustomFieldDefinitions();
        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            if ($slug && preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                $map['{{' . $slug . '}}'] = (string) ($this->custom_fields[$slug] ?? '');
            }
        }

        return str_replace(array_keys($map), array_values($map), $text);
    }

    /**
     * Get all custom field definitions from Settings (cached).
     */
    public static function getCustomFieldDefinitions(): array
    {
        return Cache::remember('audience_custom_field_defs', 60, function () {
            if (!class_exists(\JanDev\UserManagement\Models\Setting::class)) {
                return [];
            }
            $definitions = \JanDev\UserManagement\Models\Setting::get('audience', 'custom_fields', []);
            return is_array($definitions) ? $definitions : [];
        });
    }

    public function emailAudienceGroup()
    {
        return $this->belongsTo(EmailAudienceGroup::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    public function scopeByEmailAudienceGroup($query, $groupId)
    {
        return $query->where('email_audience_group_id', $groupId);
    }

    public function scopeNotBounced($query)
    {
        return $query->where('bounced', false);
    }

    public function scopeBounced($query)
    {
        return $query->where('bounced', true);
    }

    public function scopeCanReceiveEmail($query)
    {
        return $query->where('is_active', true)->where('bounced', false);
    }
}
