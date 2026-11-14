<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\LabTech;
use App\Models\Term;
use App\Services\LabTechWorkloadService;
use Illuminate\Http\Request;

class LabTechController extends Controller
{
    public function index(LabTechWorkloadService $workloads)
    {
        $activeTerm = Term::where('is_active', true)->first();
        $labTechs = LabTech::orderBy('name')->get();
        $workloadRows = collect();

        if ($activeTerm) {
            $workloadRows = $workloads->summariesForTerm($activeTerm)->keyBy(fn (array $row) => $row['lab_tech']->id);
        }

        return view('aop.labtechs.index', [
            'activeTerm' => $activeTerm,
            'labTechs' => $labTechs,
            'workloadRows' => $workloadRows,
        ]);
    }

    public function create()
    {
        return view('aop.labtechs.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'weekly_hour_limit' => ['required', 'numeric', 'min:0', 'max:168'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        LabTech::create($data);

        return redirect()->route('aop.labtechs.index')->with('status', 'Lab tech created.');
    }

    public function edit(LabTech $labTech)
    {
        return view('aop.labtechs.edit', [
            'labTech' => $labTech,
        ]);
    }

    public function update(Request $request, LabTech $labTech)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'weekly_hour_limit' => ['required', 'numeric', 'min:0', 'max:168'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $labTech->update($data);

        return redirect()->route('aop.labtechs.index')->with('status', 'Lab tech updated.');
    }
}
