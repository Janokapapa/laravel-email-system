<x-filament-widgets::widget>
    <div wire:poll.5s>
        @php
            $activeJobs = $this->getActiveJobs();
            $recentJobs = $this->getRecentJobs();
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                Job Progress
            </x-slot>

            @if($activeJobs->isEmpty() && $recentJobs->isEmpty())
                <p style="color: #9ca3af; font-size: 0.875rem; padding: 0.5rem 0;">
                    No active or recent jobs.
                </p>
            @endif

            @if($activeJobs->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    @foreach($activeJobs as $job)
                        <div style="border-radius: 0.5rem; border: 1px solid #fbbf24; background: #fffbeb; padding: 0.75rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="position: relative; display: inline-flex; height: 0.625rem; width: 0.625rem;">
                                        <span style="position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 9999px; background: #fbbf24; opacity: 0.75; animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite;"></span>
                                        <span style="position: relative; display: inline-flex; border-radius: 9999px; height: 0.625rem; width: 0.625rem; background: #f59e0b;"></span>
                                    </span>
                                    <span style="font-size: 0.875rem; font-weight: 500; color: #111827;">{{ $job->name }}</span>
                                </div>
                                <span style="font-size: 0.75rem; font-family: monospace; color: #4b5563;">
                                    {{ number_format($job->processed) }}/{{ number_format($job->total) }} — {{ $job->getProgressPercent() }}%
                                </span>
                            </div>

                            {{-- Progress bar --}}
                            <div style="width: 100%; background: #e5e7eb; border-radius: 9999px; height: 0.625rem;">
                                <div style="background: #f59e0b; height: 0.625rem; border-radius: 9999px; transition: width 0.5s; width: {{ min($job->getProgressPercent(), 100) }}%;"></div>
                            </div>

                            @if($job->failed > 0)
                                <p style="margin-top: 0.25rem; font-size: 0.75rem; color: #dc2626;">
                                    {{ number_format($job->failed) }} failed
                                </p>
                            @endif

                            <p style="margin-top: 0.25rem; font-size: 0.75rem; color: #9ca3af;">
                                Started {{ $job->started_at->diffForHumans() }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Recent completed/failed jobs --}}
            @if($recentJobs->isNotEmpty())
                @if($activeJobs->isNotEmpty())
                    <div style="margin: 0.75rem 0; border-top: 1px solid #e5e7eb;"></div>
                @endif

                <div style="display: flex; flex-direction: column; gap: 0.375rem;">
                    <p style="font-size: 0.75rem; font-weight: 500; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em;">
                        Recent (24h)
                    </p>
                    @foreach($recentJobs as $job)
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.375rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem;
                            background: {{ $job->status === 'completed' ? '#ecfdf5' : '#fef2f2' }};">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                @if($job->status === 'completed')
                                    <svg style="height: 1rem; width: 1rem; color: #10b981;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/>
                                    </svg>
                                @else
                                    <svg style="height: 1rem; width: 1rem; color: #ef4444;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                                <span style="color: #374151;">{{ $job->name }}</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.75rem; color: #9ca3af;">
                                <span style="font-family: monospace;">{{ number_format($job->processed) }}/{{ number_format($job->total) }}</span>
                                @if($job->failed > 0)
                                    <span style="color: #dc2626;">{{ $job->failed }} failed</span>
                                @endif
                                <span>{{ $job->completed_at?->diffForHumans() }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
