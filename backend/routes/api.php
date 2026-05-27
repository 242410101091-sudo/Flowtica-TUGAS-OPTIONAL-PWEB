<?php

use App\Http\Controllers\TodoController;

Route::apiResource('todos', TodoController::class);
Route::get('todos-stats', [TodoController::class, 'stats']);