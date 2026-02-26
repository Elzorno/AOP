<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index()
    {
        return view('aop.rooms.index', [
            'rooms' => Room::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('aop.rooms.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255','unique:rooms,name'],
            'building' => ['nullable','string','max:255'],
            'room_number' => ['nullable','string','max:255'],
            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        Room::create($data);

        return redirect()->route('aop.rooms.index')->with('status', 'Room created.');
    }

    public function edit(Room $room)
    {
        return view('aop.rooms.edit', ['room' => $room]);
    }

    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255','unique:rooms,name,'.$room->id],
            'building' => ['nullable','string','max:255'],
            'room_number' => ['nullable','string','max:255'],
            'is_active' => ['nullable','boolean'],
        ]);

        $data['is_active'] = (bool)($data['is_active'] ?? true);

        $room->update($data);

        return redirect()->route('aop.rooms.index')->with('status', 'Room updated.');
    }
}
