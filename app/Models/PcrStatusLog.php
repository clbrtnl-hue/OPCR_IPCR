<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PcrStatusLog extends Model
{
    protected $table = 'pcr_status_logs';

    protected $guarded = [];

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    public static function record(int $formId, ?string $from, string $to, ?string $note = null): void
    {
        try {
            static::create([
                'form_id'           => $formId,
                'from_status'       => $from,
                'to_status'         => $to,
                'note'              => $note ? mb_substr($note, 0, 500) : null,
                'performed_by'      => Auth::id(),
                'performed_by_name' => Auth::user()->name ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PCR status log failed: ' . $e->getMessage());
        }
    }
}
