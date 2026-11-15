<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermScheduleException extends Model
{
    public const TYPE_HOLIDAY = 'holiday';

    public const TYPE_CANCELLATION = 'cancellation';

    public const TYPE_MAKEUP = 'makeup';

    protected $fillable = [
        'term_id', 'scheduled_event_id', 'occurs_on', 'exception_type', 'notes',
    ];

    protected $casts = ['occurs_on' => 'date'];

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function scheduledEvent(): BelongsTo
    {
        return $this->belongsTo(ScheduledEvent::class);
    }
}
