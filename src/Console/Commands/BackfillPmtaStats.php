<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class BackfillPmtaStats extends Command
{
    protected $signature = 'pmta:backfill-stats {server : PMTA server alias (e.g. caspmta1)}';

    protected $description = 'Run push-stats.py --backfill on the given PMTA server (SSH wrapper)';

    public function handle(): int
    {
        $server = $this->argument('server');
        $allowed = config('email-system.pmta.servers', []);

        if (!in_array($server, $allowed, true)) {
            $this->error("Unknown server: {$server}");
            $this->line('Allowed: ' . implode(', ', $allowed));
            return Command::FAILURE;
        }

        $scriptPath = '/usr/local/bin/push-stats.py';

        $sshOpts = '-o ConnectTimeout=10 -o BatchMode=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3';

        $this->info("Checking that {$scriptPath} exists on {$server}...");
        try {
            $check = Process::timeout(15)->run("ssh {$sshOpts} {$server} 'test -f {$scriptPath}'");
        } catch (ProcessTimedOutException | LaravelProcessTimedOutException) {
            $this->error("Timeout while connecting to {$server} (existence check). Check SSH access.");
            return Command::FAILURE;
        }

        if ($check->failed()) {
            $this->error("push-stats.py not found on {$server}.");
            $this->line('Run install-stats-pusher.sh first.');
            return Command::FAILURE;
        }

        $this->info("Running --backfill on {$server} (this may take a few minutes)...");
        try {
            $run = Process::timeout(600)->run("ssh {$sshOpts} {$server} '/usr/bin/python3 {$scriptPath} --backfill {$server}'");
        } catch (ProcessTimedOutException | LaravelProcessTimedOutException) {
            $this->error("Timeout (>10 min) while running --backfill on {$server}. The remote script may be stuck.");
            return Command::FAILURE;
        }

        if ($run->output()) {
            $this->line($run->output());
        }
        if ($run->errorOutput()) {
            $this->line($run->errorOutput());
        }

        if ($run->failed()) {
            $this->error("Backfill failed on {$server} (exit {$run->exitCode()})");
            return Command::FAILURE;
        }

        $this->info("Backfill done on {$server}.");
        return Command::SUCCESS;
    }
}
