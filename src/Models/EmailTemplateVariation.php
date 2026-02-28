<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EmailTemplateVariation extends Model
{
    protected $fillable = [
        'email_template_id',
        'subject',
        'body',
        'sort_order',
    ];

    public function emailTemplate()
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public static function syncForTemplate(EmailTemplate $template, array $items): void
    {
        DB::transaction(function () use ($template, $items) {
            $template->variations()->delete();

            $sortOrder = 0;
            foreach ($items as $item) {
                $subject = trim($item['subject'] ?? '');
                $body    = $item['body'] ?? '';

                if ($subject === '' && strip_tags($body) === '') {
                    continue;
                }

                $template->variations()->create([
                    'subject'    => $subject,
                    'body'       => $body,
                    'sort_order' => $sortOrder++,
                ]);
            }
        });
    }
}
