<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use Illuminate\Http\Request;

class InstructorController extends Controller
{
    public function index()
    {
        return view('aop.instructors.index', [
            'instructors' => Instructor::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('aop.instructors.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['nullable','email','max:255'],
            'is_full_time' => ['nullable','boolean'],
            'color_hex' => ['nullable','regex:/^#?(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/'],
            'is_active' => ['nullable','boolean'],
        ], [
            'color_hex.regex' => 'Color must be a valid 3, 6, or 8 digit hex value, with or without a leading #.',
        ]);

        $data['is_full_time'] = (bool)($data['is_full_time'] ?? false);
        $data['is_active'] = (bool)($data['is_active'] ?? true);

        Instructor::create($data);

        return redirect()->route('aop.instructors.index')->with('status', 'Instructor created.');
    }

    public function edit(Instructor $instructor)
    {
        return view('aop.instructors.edit', ['instructor' => $instructor]);
    }

    public function update(Request $request, Instructor $instructor)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['nullable','email','max:255'],
            'is_full_time' => ['nullable','boolean'],
            'color_hex' => ['nullable','regex:/^#?(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/'],
            'is_active' => ['nullable','boolean'],
        ], [
            'color_hex.regex' => 'Color must be a valid 3, 6, or 8 digit hex value, with or without a leading #.',
        ]);

        $data['is_full_time'] = (bool)($data['is_full_time'] ?? false);
        $data['is_active'] = (bool)($data['is_active'] ?? true);

        $instructor->update($data);

        return redirect()->route('aop.instructors.index')->with('status', 'Instructor updated.');
    }
}
