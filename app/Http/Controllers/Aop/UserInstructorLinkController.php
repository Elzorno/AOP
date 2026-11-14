<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserInstructorLinkController extends Controller
{
    public function index(): View
    {
        return view('aop.accounts.index', [
            'users' => User::query()->with('instructor')->orderBy('name')->get(),
            'instructors' => Instructor::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'instructor_id' => [
                'nullable',
                'integer',
                Rule::exists('instructors', 'id'),
                Rule::unique('users', 'instructor_id')->ignore($user->id),
            ],
        ]);

        $user->instructor_id = $data['instructor_id'] ?? null;
        $user->save();

        return back()->with('status', $user->instructor_id
            ? 'Instructor account link updated.'
            : 'Instructor account link removed.');
    }
}
