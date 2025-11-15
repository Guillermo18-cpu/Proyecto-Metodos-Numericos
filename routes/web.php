<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NutrientSolverController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/solver', [NutrientSolverController::class, 'index']);
Route::post('/solver', [NutrientSolverController::class, 'solve']);

Route::get('/', [NutrientSolverController::class, 'index'])->name('solver.index');
Route::post('/solver', [NutrientSolverController::class, 'solve'])->name('solver.solve');



