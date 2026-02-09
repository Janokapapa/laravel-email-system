<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailDuplicateWatchdog extends Command
{
    protected $signature = 'email-system:watchdog {--hours=1 : Hours to look back} {--threshold=2 : Minimum duplicates to alert}';
    protected $description = 'Monitor for duplicate email sends and alert';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $threshold = (int) $this->option('threshold');

        $duplicates = DB::select("
            SELECT
                recipient,
                subject,
                reference_id,
                COUNT(*) as cnt,
                GROUP_CONCAT(id ORDER BY id) as ids,
                MIN(created_at) as first_sent,
                MAX(created_at) as last_sent
            FROM email_logs
            WHERE status = 'sent'
            AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY recipient, subject, reference_id
            HAVING cnt >= ?
            ORDER BY cnt DESC
        ", [$hours, $threshold]);

        if (empty($duplicates)) {
            $this->info("No duplicates found in the last {$hours} hour(s).");
            return 0;
        }

        $this->error("Found " . count($duplicates) . " duplicate email groups!");

        $alertData = [];
        foreach ($duplicates as $dup) {
            $this->warn(sprintf(
                "%dx - %s - %s (IDs: %s)",
                $dup->cnt,
                $dup->recipient,
                substr($dup->subject, 0, 50),
                $dup->ids
            ));

            $alertData[] = [
                'count' => $dup->cnt,
                'recipient' => $dup->recipient,
                'subject' => $dup->subject,
                'ids' => $dup->ids,
                'first_sent' => $dup->first_sent,
                'last_sent' => $dup->last_sent,
            ];
        }

        $adminEmail = config('email-system.admin_email');
        if ($adminEmail && count($duplicates) > 0) {
            $this->sendAlertEmail($adminEmail, $alertData, $hours);
        }

        return 1;
    }

    protected function sendAlertEmail(string $adminEmail, array $duplicates, int $hours): void
    {
        try {
            $appName = config('app.name', 'App');
            $body = "Email Duplicate Alert\n\n";
            $body .= "Found " . count($duplicates) . " duplicate email groups in the last {$hours} hour(s):\n\n";

            foreach ($duplicates as $dup) {
                $body .= sprintf(
                    "- %dx to %s\n  Subject: %s\n  IDs: %s\n  Time: %s - %s\n\n",
                    $dup['count'],
                    $dup['recipient'],
                    $dup['subject'],
                    $dup['ids'],
                    $dup['first_sent'],
                    $dup['last_sent']
                );
            }

            $body .= "\nPlease investigate the cause.";

            Mail::raw($body, function ($message) use ($adminEmail, $appName) {
                $message->to($adminEmail)
                    ->subject("Email Duplicate Alert - {$appName}");
            });

            $this->info("Alert email sent to {$adminEmail}");
        } catch (\Exception $e) {
            $this->error("Failed to send alert email: " . $e->getMessage());
        }
    }
}
