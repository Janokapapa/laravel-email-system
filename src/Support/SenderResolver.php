<?php

namespace JanDev\EmailSystem\Support;

use Illuminate\Support\Facades\Cache;
use JanDev\UserManagement\Models\Setting;
use JanDev\EmailSystem\Support\ProviderResolver;

class SenderResolver
{
    protected const CACHE_KEY = 'email_sender_definitions';
    protected const CACHE_TTL = 60;

    protected const PMTA_SERVERS_CACHE_KEY = 'email_pmta_servers_cache';
    protected const SMTP_SERVERS_CACHE_KEY = 'email_smtp_servers_cache';
    protected const DOMAIN_ROUTING_CACHE_KEY = 'email_domain_routing_cache';
    protected const ROUTING_PROFILES_CACHE_KEY = 'email_routing_profiles_cache';
    protected const PMTA_FAILOVER_CACHE_KEY = 'email_pmta_failover_cache';

    /**
     * Return all enabled sender definitions (cached).
     */
    public static function all(): array
    {
        return array_values(array_filter(static::allIncludingDisabled(), function (array $sender) {
            return ($sender['enabled'] ?? true) === true;
        }));
    }

    /**
     * Return all sender definitions including disabled ones (cached).
     */
    public static function allIncludingDisabled(): array
    {
        return Cache::remember(static::CACHE_KEY, static::CACHE_TTL, function () {
            $value = Setting::get('email', 'senders', []);
            return is_array($value) ? $value : [];
        });
    }

    /**
     * Return a sender config by name, or null if not found.
     */
    public static function get(string $name): ?array
    {
        foreach (static::all() as $sender) {
            if (isset($sender['name']) && $sender['name'] === $name) {
                return $sender;
            }
        }
        return null;
    }

    /**
     * Return the default sender (is_default = true), or null.
     */
    public static function getDefault(): ?array
    {
        foreach (static::all() as $sender) {
            if (!empty($sender['is_default'])) {
                return $sender;
            }
        }
        return null;
    }

    /**
     * Return options array for Filament Select: ['name' => 'Name (type)'].
     */
    public static function options(): array
    {
        $options = [];
        foreach (static::all() as $sender) {
            if (isset($sender['name'])) {
                $label = ($sender['from_name'] ?? $sender['name']) . ' (' . ($sender['type'] ?? '?') . ')';
                $options[$sender['name']] = $label;
            }
        }
        return $options;
    }

    /**
     * Forget the cache. Called from Setting model after email.senders is saved.
     */
    public static function forgetCache(): void
    {
        Cache::forget(static::CACHE_KEY);
        Cache::forget(static::PMTA_FAILOVER_CACHE_KEY);
    }

    /**
     * Return all PMTA server definitions (cached).
     * Each server: { name, host, user, port, ssh_key, tmp_path, pickup_path, virtual_mta, bounce_domain, batch_size }
     */
    public static function pmtaServers(): array
    {
        return Cache::remember(static::PMTA_SERVERS_CACHE_KEY, static::CACHE_TTL, function () {
            $value = Setting::get('email', 'pmta_servers', []);
            return is_array($value) ? $value : [];
        });
    }

    /**
     * Return a PMTA server config by name, or null if not found.
     */
    public static function pmtaServer(string $name): ?array
    {
        foreach (static::pmtaServers() as $server) {
            if (isset($server['name']) && $server['name'] === $name) {
                return $server;
            }
        }
        return null;
    }

    /**
     * Return all SMTP server definitions (cached).
     * Each server: { name, host, port, encryption, username, password, from_address, from_name }
     */
    public static function smtpServers(): array
    {
        return Cache::remember(static::SMTP_SERVERS_CACHE_KEY, static::CACHE_TTL, function () {
            $value = Setting::get('email', 'smtp_servers', []);
            return is_array($value) ? $value : [];
        });
    }

    /**
     * Return an SMTP server config by name, or null if not found.
     */
    public static function smtpServer(string $name): ?array
    {
        foreach (static::smtpServers() as $server) {
            if (isset($server['name']) && $server['name'] === $name) {
                return $server;
            }
        }
        return null;
    }

    /**
     * Resolve the full SMTP config for a sender, merging smtp_servers entry with sender identity fields.
     * Priority: sender's smtp_server reference (DB) > sender's smtp_mailer (config/mail.php name).
     */
    public static function resolveFullSmtpConfig(array $sender): array
    {
        $serverConfig = [];

        // Resolve server by reference name from smtp_servers setting
        if (!empty($sender['smtp_server'])) {
            $serverConfig = static::smtpServer($sender['smtp_server']) ?? [];
        }

        if (!empty($serverConfig)) {
            // Use DB-configured server — merge with sender identity fields
            return array_merge($serverConfig, [
                'from_address' => $sender['from_address'] ?? ($serverConfig['from_address'] ?? ''),
                'from_name'    => $sender['from_name'] ?? ($serverConfig['from_name'] ?? ''),
                'reply_to'     => $sender['reply_to'] ?? '',
                'smtp_mailer'  => null, // use dynamic mailer built from server config
            ]);
        }

        // Fallback: use smtp_mailer (config/mail.php mailer name)
        return array_merge($sender, [
            'smtp_mailer' => $sender['smtp_mailer'] ?? config('email-system.smtp.mailer', 'smtp'),
        ]);
    }

    /**
     * Return all routing profiles (cached).
     * Each profile: { name, rules: [{ provider, server }] }
     */
    public static function routingProfiles(): array
    {
        return Cache::remember(static::ROUTING_PROFILES_CACHE_KEY, static::CACHE_TTL, function () {
            $value = Setting::get('email', 'routing_profiles', []);
            return is_array($value) ? $value : [];
        });
    }

    /**
     * Return a routing profile by name, or null if not found.
     */
    public static function routingProfile(string $name): ?array
    {
        foreach (static::routingProfiles() as $profile) {
            if (isset($profile['name']) && $profile['name'] === $name) {
                return $profile;
            }
        }
        return null;
    }

    /**
     * Return routing profile options for Filament Select: ['name' => 'name'].
     */
    public static function routingProfileOptions(): array
    {
        $options = [];
        foreach (static::routingProfiles() as $profile) {
            if (isset($profile['name'])) {
                $options[$profile['name']] = $profile['name'];
            }
        }
        return $options;
    }

    /**
     * Return the PMTA failover map (cached).
     * Format: ['caspmta3' => 'caspmta1', 'caspmta1' => 'caspmta3']
     */
    public static function pmtaFailoverMap(): array
    {
        return Cache::remember(static::PMTA_FAILOVER_CACHE_KEY, static::CACHE_TTL, function () {
            $rules = Setting::get('email', 'pmta_failover', []);
            if (!is_array($rules)) {
                return [];
            }
            $map = [];
            foreach ($rules as $rule) {
                if (!empty($rule['server']) && !empty($rule['fallback'])) {
                    $map[$rule['server']] = $rule['fallback'];
                }
            }
            return $map;
        });
    }

    /**
     * Return domain routing rules as a flat map: ['microsoft' => 'caspmta3', 'yahoo' => 'caspmta1', ...]
     * @deprecated Use routingProfiles() and resolveServerForRecipient($email, $sender) instead.
     */
    public static function domainRouting(): array
    {
        $rules = Cache::remember(static::DOMAIN_ROUTING_CACHE_KEY, static::CACHE_TTL, function () {
            $value = Setting::get('email', 'domain_routing', []);
            return is_array($value) ? $value : [];
        });

        $map = [];
        foreach ($rules as $rule) {
            if (isset($rule['provider'], $rule['server']) && $rule['provider'] !== '') {
                $map[$rule['provider']] = $rule['server'];
            }
        }
        return $map;
    }

    /**
     * Resolve the PMTA server config for a recipient email address.
     * Uses sender's routing_profile if set, otherwise falls back to sender's pmta_server.
     */
    public static function resolveServerForRecipient(string $email, ?array $sender = null): ?array
    {
        // If sender has a routing_profile, use it
        if ($sender && !empty($sender['routing_profile'])) {
            $profile = static::routingProfile($sender['routing_profile']);
            if ($profile && !empty($profile['rules'])) {
                $map = [];
                foreach ($profile['rules'] as $rule) {
                    if (isset($rule['provider'], $rule['server']) && $rule['provider'] !== '') {
                        $map[$rule['provider']] = $rule['server'];
                    }
                }
                if (!empty($map)) {
                    $provider = ProviderResolver::resolve($email);
                    $serverName = $map[$provider] ?? $map['default'] ?? null;
                    if (!empty($serverName)) {
                        return static::pmtaServer($serverName);
                    }
                }
            }
        }

        // Fallback: sender's pmta_server
        if ($sender && !empty($sender['pmta_server'])) {
            return static::pmtaServer($sender['pmta_server']);
        }

        // Legacy fallback: global domain_routing
        $routing = static::domainRouting();
        if (!empty($routing)) {
            $provider = ProviderResolver::resolve($email);
            $serverName = $routing[$provider] ?? $routing['default'] ?? null;
            if (!empty($serverName)) {
                return static::pmtaServer($serverName);
            }
        }

        return null;
    }

    /**
     * Resolve a per-server, per-provider virtual-MTA override.
     *
     * Config shape (config/email-system.php → pmta.provider_virtual_mta):
     *   ['caspmta4' => ['gmail' => 'icloudpool', 'yahoo' => 'icloudpool', 'icloud' => 'icloudpool']]
     *
     * Given the resolved PMTA server name and the recipient email, classifies the
     * recipient's inbox provider via ProviderResolver and returns the configured
     * vMTA override (e.g. a clean IP pool) for that server+provider, or null when
     * no override is configured. Config/DB-driven so it can be enabled per
     * server+provider without code changes.
     */
    public static function providerVirtualMta(?string $serverName, string $email): ?string
    {
        if ($serverName === null || $serverName === '') {
            return null;
        }

        $map = config('email-system.pmta.provider_virtual_mta', []);
        if (!is_array($map) || empty($map[$serverName]) || !is_array($map[$serverName])) {
            return null;
        }

        $provider = ProviderResolver::resolve($email);
        $vmta = $map[$serverName][$provider] ?? null;

        return (is_string($vmta) && $vmta !== '') ? $vmta : null;
    }

    /**
     * Apply per-email (campaign-level) sender overrides on top of a resolved
     * sender definition. The campaign may override the From address, display
     * name and Reply-To without changing the selected sender definition; those
     * per-email values must win so the actual From/DKIM domain matches what the
     * campaign selected. Empty overrides are ignored (definition wins).
     */
    public static function applyEmailOverrides(
        array $senderConfig,
        ?string $sender,
        ?string $displayName,
        ?string $replyTo
    ): array {
        if (!empty($sender)) {
            $senderConfig['from_address'] = $sender;
        }
        if (!empty($displayName)) {
            $senderConfig['from_name'] = $displayName;
        }
        if (!empty($replyTo)) {
            $senderConfig['reply_to'] = $replyTo;
        }
        return $senderConfig;
    }

    /**
     * Resolve the full PMTA config for a sender, merging server config with sender fields.
     * Priority for virtual_mta: sender's pmta_virtual_mta (if set) > server's virtual_mta.
     * Backward compat: if sender has inline pmta_host, use inline fields as fallback.
     */
    public static function resolveFullPmtaConfig(array $sender): array
    {
        $serverConfig = [];

        // Resolve server by reference name first
        if (!empty($sender['pmta_server'])) {
            $serverConfig = static::pmtaServer($sender['pmta_server']) ?? [];
        }

        // Fallback: sender has inline pmta_host (backward compat)
        if (empty($serverConfig) && !empty($sender['pmta_host'])) {
            $serverConfig = [
                'name'         => null,
                'host'         => $sender['pmta_host'],
                'user'         => $sender['pmta_user'] ?? 'root',
                'port'         => $sender['pmta_port'] ?? 22,
                'ssh_key'      => $sender['pmta_ssh_key'] ?? '',
                'tmp_path'     => $sender['pmta_tmp_path'] ?? '/tmp-pickup',
                'pickup_path'  => $sender['pmta_pickup_path'] ?? '/pickup',
                'virtual_mta'  => $sender['pmta_virtual_mta'] ?? 'all',
                'bounce_domain' => '',
                'batch_size'   => null,
            ];
        }

        // Merge: sender fields take priority for identity fields
        $config = array_merge($serverConfig, [
            'from_address' => $sender['from_address'] ?? '',
            'from_name'    => $sender['from_name'] ?? '',
            'reply_to'     => $sender['reply_to'] ?? '',
        ]);

        // Sender's pmta_virtual_mta overrides server's virtual_mta if non-empty
        if (!empty($sender['pmta_virtual_mta'])) {
            $config['virtual_mta'] = $sender['pmta_virtual_mta'];
        }

        return $config;
    }
}
