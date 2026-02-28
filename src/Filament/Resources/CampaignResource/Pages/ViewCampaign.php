<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string
    {
        return $this->record->name ?? __('Campaign');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Campaign Details'))
                ->schema([
                    Placeholder::make('campaign_info')
                        ->label('')
                        ->content(function (): HtmlString {
                            $r = $this->record;
                            $html = '<div class="grid grid-cols-2 gap-4 text-sm">';
                            $html .= '<div><strong>' . __('Sender') . ':</strong> ' . e($r->sender_display_name ?? $r->sender_name) . ' &lt;' . e($r->sender_address) . '&gt;</div>';
                            $html .= '<div><strong>' . __('Subject') . ':</strong> ' . e($r->subject) . '</div>';
                            $html .= '<div><strong>' . __('Content Type') . ':</strong> ' . e(strtoupper($r->content_type ?? 'html')) . '</div>';
                            $html .= '<div><strong>' . __('Sent at') . ':</strong> ' . ($r->sent_at ? $r->sent_at->format('Y-m-d H:i:s') : '—') . '</div>';

                            // Lists
                            $groupIds = $r->audience_group_ids ?? [];
                            $listNames = collect($groupIds)->map(function ($id) {
                                $group = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($id);
                                return $group ? $group->name : __('Deleted');
                            })->join(', ');
                            $html .= '<div class="col-span-2"><strong>' . __('Lists') . ':</strong> ' . e($listNames ?: '—') . '</div>';

                            // Skip providers
                            $skipProviders = $r->skip_providers ?? [];
                            if (!empty($skipProviders)) {
                                $providerLabels = ['yahoo' => 'Yahoo', 'microsoft' => 'Microsoft', 'gmail' => 'Gmail', 'icloud' => 'iCloud'];
                                $skipped = collect($skipProviders)->map(fn ($p) => $providerLabels[$p] ?? $p)->join(', ');
                                $html .= '<div class="col-span-2"><strong>' . __('Skipped Providers') . ':</strong> ' . e($skipped) . '</div>';
                            }

                            // Variations count
                            $variationCount = count($r->variations ?? []);
                            if ($variationCount > 0) {
                                $html .= '<div><strong>' . __('Variations') . ':</strong> ' . $variationCount . '</div>';
                            }

                            $html .= '</div>';
                            return new HtmlString($html);
                        })
                        ->columnSpanFull(),
                ]),

            Section::make(__('Statistics'))
                ->schema([
                    Placeholder::make('stats')
                        ->label('')
                        ->content(function (): HtmlString {
                            $stats = EmailLog::where('campaign_id', $this->record->id)
                                ->selectRaw("
                                    COUNT(*) as total,
                                    SUM(CASE WHEN status IN ('sent','spooled') THEN 1 ELSE 0 END) as sent,
                                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                                    SUM(clicked) as clicked_count,
                                    SUM(complained) as complained_count,
                                    SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) as hard_bounce,
                                    SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) as soft_bounce
                                ")
                                ->first();

                            return new HtmlString(
                                view('email-system::filament.campaign-stats', [
                                    'record' => $this->record,
                                    'stats' => $stats,
                                ])->render()
                            );
                        })
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
