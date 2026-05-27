<?php
namespace App\Http\Controllers;
use App\Models\Todo;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index()
    {
        $todos = Todo::orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $todos]);
    }

    public function store(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255']);
        $todo = Todo::create($request->all());
        return response()->json(['data' => $todo], 201);
    }

    public function update(Request $request, Todo $todo)
    {
        $todo->update($request->all());
        return response()->json(['data' => $todo]);
    }

    public function destroy(Todo $todo)
    {
        $todo->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function stats()
    {
        $total      = Todo::count();
        $completed  = Todo::where('is_completed', true)->count();
        $inprogress = Todo::where('status', 'inprogress')->count();
        $overdue    = Todo::where('is_completed', false)
                          ->where('deadline', '<', now())
                          ->whereNotNull('deadline')
                          ->count();
        $productivity = $total > 0 ? round(($completed / $total) * 100) : 0;

        return response()->json(['data' => compact(
            'total', 'completed', 'inprogress', 'overdue', 'productivity'
        )]);
    }
}