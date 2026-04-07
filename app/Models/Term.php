<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    protected $fillable = [
        'code','name','starts_on','ends_on','is_active','status',
        'weeks_in_term','slot_minutes','buffer_minutes',
        'enforce_schedule_blocks',
        'allowed_hours_json',
        'schedule_locked','schedule_locked_at','schedule_locked_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'allowed_hours_json' => 'array',
        'schedule_locked' => 'boolean',
        'schedule_locked_at' => 'datetime',
        'enforce_schedule_blocks' => 'boolean',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
    public function officeHourBlocks(): HasMany { return $this->hasMany(OfficeHourBlock::class); }

    public function cloneToDraft(): self
    {
        $draft = $this->replicate(['schedule_locked', 'schedule_locked_at', 'schedule_locked_by_user_id']);
        $draft->status = 'draft';
        $draft->name = $this->name . ' (Draft ' . now()->format('Y-m-d H:i') . ')';
        $draft->code = $this->code . '-draft-' . now()->timestamp;
        $draft->push();

        foreach ($this->offerings as $offering) {
            $newOffering = $offering->replicate(['term_id']);
            $newOffering->term_id = $draft->id;
            $newOffering->push();

            foreach ($offering->sections as $section) {
                $newSection = $section->replicate(['offering_id']);
                $newSection->offering_id = $newOffering->id;
                $newSection->push();

                foreach ($section->meetingBlocks as $block) {
                    $newBlock = $block->replicate(['section_id']);
                    $newBlock->section_id = $newSection->id;
                    $newBlock->push();
                }
            }
        }

        foreach ($this->officeHourBlocks as $block) {
            $newBlock = $block->replicate(['term_id']);
            $newBlock->term_id = $draft->id;
            $newBlock->push();
        }

        return $draft;
    }
}
