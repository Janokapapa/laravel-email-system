<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\Widget;
use JanDev\EmailSystem\Models\JobTracker;

class JobProgressWidget extends Widget
{
    protected string $view = 'email-system::widgets.job-progress';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public function getActiveJobs()
    {
        return JobTracker::active()->orderBy('started_at', 'desc')->get();
    }

    public function getRecentJobs()
    {
        return JobTracker::recent()
            ->whereIn('status', ['completed', 'failed'])
            ->orderBy('completed_at', 'desc')
            ->limit(10)
            ->get();
    }
}
