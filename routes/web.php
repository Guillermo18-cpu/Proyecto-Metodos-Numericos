<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NutrientSolverController;

Route::get('/', [NutrientSolverController::class, 'index'])->name('solver.index');

Route::get('/solver', [NutrientSolverController::class, 'index']);
Route::post('/solver', [NutrientSolverController::class, 'solve']);
Route::post('/solver', [NutrientSolverController::class, 'solve'])->name('solver.solve');

