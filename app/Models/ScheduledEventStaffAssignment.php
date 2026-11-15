<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledEventStaffAssignment extends Model
{
    protected $fillable = ['scheduled_event_id', 'staff_type', 'staff_id', 'instructor_id', 'lab_tech_id', 'role'];

    public function scheduledEvent(): BelongsTo
    {
        return $this->belongsTo(ScheduledEvent::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function labTech(): BelongsTo
    {
        return $this->belongsTo(LabTech::class);
    }
}
