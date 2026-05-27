<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index()
    {
        $todos = Todo::latest()->get();
        return response()->json(['success' => true, 'data' => $todos]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'    => 'required|string|max:255',
            'priority' => 'in:low,medium,high,critical',
            'status'   => 'in:todo,inprogress,done',
            'progress' => 'integer|min:0|max:100',
            'deadline' => 'nullable|date',
        ]);

        $todo = Todo::create([
            'title'        => $request->title,
            'notes'        => $request->notes,
            'status'       => $request->status ?? 'todo',
            'priority'     => $request->priority ?? 'medium',
            'category'     => $request->category ?? 'General',
            'progress'     => $request->progress ?? 0,
            'is_completed' => false,
            'deadline'     => $request->deadline,
        ]);

        return response()->json(['success' => true, 'data' => $todo], 201);
    }

    public function show($id)
    {
        $todo = Todo::find($id);
        if (!$todo) return response()->json(['success' => false, 'message' => 'Not found'], 404);
        return response()->json(['success' => true, 'data' => $todo]);
    }

    public function update(Request $request, $id)
    {
        $todo = Todo::find($id);
        if (!$todo) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $todo->update($request->only([
            'title', 'notes', 'status', 'priority',
            'category', 'progress', 'is_completed', 'deadline'
        ]));

        if ($request->status === 'done') {
            $todo->update(['is_completed' => true, 'progress' => 100]);
        }

        return response()->json(['success' => true, 'data' => $todo]);
    }

    public function destroy($id)
    {
        $todo = Todo::find($id);
        if (!$todo) return response()->json(['success' => false, 'message' => 'Not found'], 404);
        $todo->delete();
        return response()->json(['success' => true, 'message' => 'Deleted']);
    }

    public function stats()
    {
        $total      = Todo::count();
        $completed  = Todo::where('is_completed', true)->count();
        $inprogress = Todo::where('status', 'inprogress')->count();
        $overdue    = Todo::where('is_completed', false)
                         ->whereNotNull('deadline')
                         ->where('deadline', '<', now())
                         ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => $total,
                'completed'    => $completed,
                'inprogress'   => $inprogress,
                'overdue'      => $overdue,
                'productivity' => $total > 0 ? round(($completed / $total) * 100) : 0,
            ]
        ]);
    }
}