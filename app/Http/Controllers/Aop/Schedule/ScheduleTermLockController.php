<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Term;

class ScheduleTermLockController extends Controller
{
    public function lock(Term $term)
    {
        return $this->retireLegacyLock($term);
    }

    public function unlock(Term $term)
    {
        return $this->retireLegacyLock($term);
    }

    private function retireLegacyLock(Term $term)
    {
        if ($term->schedule_locked || $term->schedule_locked_at || $term->schedule_locked_by_user_id) {
            $term->update([
                'schedule_locked' => false,
                'schedule_locked_at' => null,
                'schedule_locked_by_user_id' => null,
            ]);
        }

        return redirect()
            ->route('aop.schedule.reports.index', $term)
            ->with('status', 'Schedule locking has been retired. Download the current registrar CSV whenever it is needed.');
    }
}
