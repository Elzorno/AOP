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
            'credits' => ['required','numeric','min:0','max:30'],
            'lecture_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'lab_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'description' => ['nullable','string'],
            'prereq_text' => ['nullable','string'],
            'coreq_text' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        CatalogCourse::create($data);

        return redirect()->route('aop.catalog.index')->with('status', 'Catalog course created.');
    }

    public function edit(CatalogCourse $catalogCourse)
    {
        return view('aop.catalog.edit', ['course' => $catalogCourse]);
    }

    public function update(Request $request, CatalogCourse $catalogCourse)
    {
        $data = $request->validate([
            'code' => ['required','string','max:50','unique:catalog_courses,code,'.$catalogCourse->id],
            'title' => ['required','string','max:255'],
            'credits' => ['required','numeric','min:0','max:30'],
            'lecture_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'lab_hours_per_week' => ['nullable','numeric','min:0','max:40'],
            'description' => ['nullable','string'],
            'prereq_text' => ['nullable','string'],
            'coreq_text' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        $catalogCourse->update($data);

        return redirect()->route('aop.catalog.index')->with('status', 'Catalog course updated.');
    }
}
