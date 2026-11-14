<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Term;

class ScheduleTermLockController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    public function lock()
    {
        return $this->retireLegacyLock();
    }

    public function unlock()
    {
        return $this->retireLegacyLock();
    }

    private function retireLegacyLock()
    {
        $term = $this->activeTermOrFail();

        if ($term->schedule_locked || $term->schedule_locked_at || $term->schedule_locked_by_user_id) {
            $term->update([
                'schedule_locked' => false,
                'schedule_locked_at' => null,
                'schedule_locked_by_user_id' => null,
            ]);
        }

        return redirect()
            ->route('aop.schedule.reports.index')
            ->with('status', 'Schedule locking has been retired. Download the current registrar CSV whenever it is needed.');
    }
}
