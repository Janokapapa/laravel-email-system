<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class JobTracker extends Model
{
    protected $fillable = [
        'type',
        'name',
        'total',
        'processed',
        'failed',
        'status',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total' => 'integer',
        'processed' => 'integer',
        'failed' => 'integer',
    ];

    // Buffering for increment optimization
    private int $buffer = 0;
    private int $failedBuffer = 0;
    private float $lastFlush = 0;
    private int $flushEvery = 10;
    private float $flushInterval = 5.0; // seconds

    public static function start(string $type, string $name, int $total, array $meta = []): static
    {
        return static::create([
            'type' => $type,
            'name' => $name,
            'total' => $total,
            'processed' => 0,
            'failed' => 0,
            'status' => 'running',
            'meta' => $meta,
            'started_at' => Carbon::now(),
        ]);
    }

    public function getProgressPercent(): float
    {
        if ($this->total === 0) {
            return 0;
        }

        return round(($this->processed / $this->total) * 100, 1);
    }

    /**
     * Buffered increment — flushes to DB every N items or every 5 seconds.
     */
    public function incrementProgress(int $count = 1): void
    {
        $this->buffer += $count;

        if ($this->lastFlush === 0.0) {
            $this->lastFlush = microtime(true);
        }

        $elapsed = microtime(true) - $this->lastFlush;

        if ($this->buffer >= $this->flushEvery || $elapsed >= $this->flushInterval) {
            $this->flush();
        }
    }

    /**
     * Buffered failed increment.
     */
    public function incrementFailed(int $count = 1): void
    {
        $this->failedBuffer += $count;
    }

    /**
     * Flush buffered counts to the database.
     */
    public function flush(): void
    {
        if ($this->buffer > 0 || $this->failedBuffer > 0) {
            static::where('id', $this->id)->update([
                'processed' => \DB::raw("processed + {$this->buffer}"),
                'failed' => \DB::raw("failed + {$this->failedBuffer}"),
            ]);

            $this->processed += $this->buffer;
            $this->failed += $this->failedBuffer;
            $this->buffer = 0;
            $this->failedBuffer = 0;
            $this->lastFlush = microtime(true);
        }
    }

    public function markCompleted(): void
    {
        $this->flush();

        $this->update([
            'status' => 'completed',
            'completed_at' => Carbon::now(),
        ]);
    }

    public function markFailed(?string $error = null): void
    {
        $this->flush();

        $meta = $this->meta ?? [];
        if ($error) {
            $meta['error'] = substr($error, 0, 500);
        }

        $this->update([
            'status' => 'failed',
            'completed_at' => Carbon::now(),
            'meta' => $meta,
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', Carbon::now()->subDay());
    }

    /**
     * Delete completed/failed trackers older than 7 days.
     */
    public static function cleanup(): int
    {
        return static::whereIn('status', ['completed', 'failed'])
            ->where('completed_at', '<', Carbon::now()->subDays(7))
            ->delete();
    }
}
