<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use Illuminate\Http\Request;

class CatalogCourseController extends Controller
{
    public function index()
    {
        return view('aop.catalog.index', [
            'courses' => CatalogCourse::orderBy('code')->get(),
        ]);
    }

    public function create()
    {
        return view('aop.catalog.create');
    }

    public function store(Request $request)
    {
                $data = $request->validate([
            'code' => ['required','string','max:50','unique:catalog_courses,code'],
            'title' => ['required','string','max:255'],
            'department' => ['nullable','string','max:255'],

            'credits' => ['required','numeric','min:0','max:30'],
            'credits_text' => ['nullable','string','max:50'],
            'credits_min' => ['nullable','numeric','min:0','max:30'],
            'credits_max' => ['nullable','numeric','min:0','max:30'],

            'lecture_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'lab_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'contact_hours_per_week' => ['nullable','numeric','min:0','max:60'],
            'course_lab_fee' => ['nullable','string','max:20'],

            'description' => ['nullable','string'],
            'objectives' => ['nullable','string'],
            'required_materials' => ['nullable','string'],

            'prereq_text' => ['nullable','string'],
            'coreq_text' => ['nullable','string'],
            'notes' => ['nullable','string'],

            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        CatalogCourse::create($data);

        return redirect()->route('aop.catalog.index')->with('status', 'Catalog course created.');
    }

    public function edit(CatalogCourse $course)
    {
        return view('aop.catalog.edit', ['course' => $course]);
    }

    public function update(Request $request, CatalogCourse $course)
    {
                $data = $request->validate([
            'code' => ['required','string','max:50','unique:catalog_courses,code,'.$course->id],
            'title' => ['required','string','max:255'],
            'department' => ['nullable','string','max:255'],

            'credits' => ['required','numeric','min:0','max:30'],
            'credits_text' => ['nullable','string','max:50'],
            'credits_min' => ['nullable','numeric','min:0','max:30'],
            'credits_max' => ['nullable','numeric','min:0','max:30'],

            'lecture_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'lab_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'contact_hours_per_week' => ['nullable','numeric','min:0','max:60'],
            'course_lab_fee' => ['nullable','string','max:20'],

            'description' => ['nullable','string'],
            'objectives' => ['nullable','string'],
            'required_materials' => ['nullable','string'],

            'prereq_text' => ['nullable','string'],
            'coreq_text' => ['nullable','string'],
            'notes' => ['nullable','string'],

            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        $course->update($data);

        return redirect()->route('aop.catalog.index')->with('status', 'Catalog course updated.');
    }
}
