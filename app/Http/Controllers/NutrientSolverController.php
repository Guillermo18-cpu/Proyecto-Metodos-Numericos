<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NutrientSolverController extends Controller
{
    public function index()
    {
        return view('nutrient_solver');
    }

    public function solve(Request $request)
    {
        // Validación básica de formato de texto (será parseado a JSON luego)
        $validator = Validator::make($request->all(), [
            'matrixA' => 'required|string',
            'vectorB' => 'required|string',
            'method' => 'required|string',
            'tolerance' => 'required|numeric',
            'maxIter' => 'required|integer|min:1|max:10000'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            return $this->respond($request, ['error' => 'Validation error', 'messages' => $errors], 422);
        }

        // Parseo JSON (esperamos un array de arrays y un array)
        $A = json_decode($request->input('matrixA'), true);
        $b = json_decode($request->input('vectorB'), true);

        if (!is_array($A) || !is_array($b)) {
            return $this->respond($request, ['error' => 'Formato JSON inválido. A debe ser [[...],[...]] y b debe ser [...]'], 400);
        }

        // Normalizar tipos (float)
        try {
            $A = $this->arrayToFloatMatrix($A);
            $b = $this->arrayToFloatVector($b);
        } catch (\Exception $e) {
            return $this->respond($request, ['error' => 'La matriz o el vector contienen valores no numéricos.'], 400);
        }

        // Tamaños
        $n = count($A);
        if ($n === 0 || count($b) !== $n) {
            return $this->respond($request, ['error' => 'La dimensión de A y b no coincide o está vacía.'], 400);
        }

        $method = $request->input('method');
        $tol = floatval($request->input('tolerance'));
        $maxIter = intval($request->input('maxIter'));

        // Chequeo de diagonal dominance
        $isDiag = $this->isDiagonallyDominant($A);

        // Ejecutar método elegido (o automático: primero Gauss-Seidel luego Jacobi)
        $result = [
            'isDiagonalDominant' => $isDiag,
            'A' => $A,
            'b' => $b,
            'methodRequested' => $method,
            'tolerance' => $tol,
            'maxIter' => $maxIter,
        ];

        $methodsAttempted = [];

        if ($method === 'jacobi' || $method === 'auto') {
            $methodsAttempted[] = 'Jacobi';
            $jacobi = $this->jacobi($A, $b, $tol, $maxIter);
            $result['jacobi'] = $jacobi;
            if ($method === 'jacobi' && $jacobi['status'] === 'ok') {
                return $this->respond($request, $result);
            }
        }

        if ($method === 'gauss' || $method === 'auto') {
            $methodsAttempted[] = 'Gauss-Seidel';
            $gs = $this->gaussSeidel($A, $b, $tol, $maxIter);
            $result['gaussSeidel'] = $gs;
            if ($method === 'gauss' && $gs['status'] === 'ok') {
                return $this->respond($request, $result);
            }
        }

        // Si ninguno converge → fallback con eliminación Gaussiana (directa)
        $result['fallback'] = [
            'method' => 'Gaussian Elimination (pivoting)',
            'solution' => $this->gaussianElimination($A, $b)
        ];

        return $this->respond($request, $result);
    }

    private function respond(Request $request, $payload, $status = 200)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json($payload, $status);
        }
        // Si no es AJAX, redirigir a la vista pasando result (compatibilidad)
        return view('nutrient_solver', ['result' => $payload]);
    }

    private function arrayToFloatMatrix($arr)
    {
        if (!is_array($arr) || count($arr) === 0) throw new \Exception("Invalid matrix");
        $n = count($arr);
        $mat = [];
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($arr[$i])) throw new \Exception("Invalid matrix row");
            $row = [];
            for ($j = 0; $j < count($arr[$i]); $j++) {
                if (!is_numeric($arr[$i][$j])) throw new \Exception("Non numeric");
                $row[] = floatval($arr[$i][$j]);
            }
            $mat[] = $row;
        }
        // check rectangular
        $m = count($mat[0]);
        foreach ($mat as $r) {
            if (count($r) !== $m) throw new \Exception("Matrix rows unequal length");
        }
        if ($m !== count($mat)) {
            // allow non-square but our solvers expect square; throw
            throw new \Exception("Se requiere matriz cuadrada (n x n)");
        }
        return $mat;
    }

    private function arrayToFloatVector($arr)
    {
        if (!is_array($arr)) throw new \Exception("Invalid vector");
        $v = [];
        foreach ($arr as $val) {
            if (!is_numeric($val)) throw new \Exception("Non numeric vector");
            $v[] = floatval($val);
        }
        return $v;
    }

    private function isDiagonallyDominant($A)
    {
        $n = count($A);
        for ($i = 0; $i < $n; $i++) {
            $diag = abs($A[$i][$i]);
            $sum = 0;
            for ($j = 0; $j < $n; $j++) if ($i !== $j) $sum += abs($A[$i][$j]);
            if ($diag < $sum) return false;
        }
        return true;
    }

    // Norma infinito residual ||Ax - b||_inf
    private function residualInf($A, $x, $b)
    {
        $n = count($A);
        $max = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $s = 0.0;
            for ($j = 0; $j < $n; $j++) $s += $A[$i][$j] * $x[$j];
            $d = abs($s - $b[$i]);
            if ($d > $max) $max = $d;
        }
        return $max;
    }

    private function jacobi($A, $b, $tol, $maxIter)
    {
        $n = count($A);
        $x = array_fill(0, $n, 0.0);
        $xNew = $x;
        $iter = 0;

        for ($k = 1; $k <= $maxIter; $k++) {
            for ($i = 0; $i < $n; $i++) {
                $sum = 0.0;
                for ($j = 0; $j < $n; $j++) {
                    if ($j !== $i) $sum += $A[$i][$j] * $x[$j];
                }
                $xNew[$i] = ($b[$i] - $sum) / $A[$i][$i];
            }
            $err = $this->residualInf($A, $xNew, $b);
            $x = $xNew;
            $iter = $k;
            if ($err <= $tol) {
                return [
                    'status' => 'ok',
                    'solution' => $x,
                    'iterations' => $iter,
                    'residual' => $err
                ];
            }
        }

        return ['status' => 'no_converge', 'iterations' => $iter];
    }

    private function gaussSeidel($A, $b, $tol, $maxIter)
    {
        $n = count($A);
        $x = array_fill(0, $n, 0.0);
        $iter = 0;

        for ($k = 1; $k <= $maxIter; $k++) {
            for ($i = 0; $i < $n; $i++) {
                $sum = 0.0;
                for ($j = 0; $j < $n; $j++) {
                    if ($j !== $i) $sum += $A[$i][$j] * $x[$j];
                }
                $x[$i] = ($b[$i] - $sum) / $A[$i][$i];
            }
            $err = $this->residualInf($A, $x, $b);
            $iter = $k;
            if ($err <= $tol) {
                return [
                    'status' => 'ok',
                    'solution' => $x,
                    'iterations' => $iter,
                    'residual' => $err
                ];
            }
        }

        return ['status' => 'no_converge', 'iterations' => $iter];
    }

    // Eliminación Gaussiana con pivoteo parcial (directo)
    private function gaussianElimination($A, $b)
    {
        $n = count($A);
        // construir matriz aumentada
        $M = [];
        for ($i = 0; $i < $n; $i++) {
            $M[$i] = $A[$i];
            $M[$i][] = $b[$i];
        }

        for ($k = 0; $k < $n; $k++) {
            // pivot
            $maxRow = $k;
            $maxVal = abs($M[$k][$k]);
            for ($r = $k + 1; $r < $n; $r++) {
                if (abs($M[$r][$k]) > $maxVal) {
                    $maxVal = abs($M[$r][$k]);
                    $maxRow = $r;
                }
            }
            if ($maxRow !== $k) {
                $tmp = $M[$k];
                $M[$k] = $M[$maxRow];
                $M[$maxRow] = $tmp;
            }
            // eliminar
            for ($i = $k + 1; $i < $n; $i++) {
                if ($M[$k][$k] == 0) continue;
                $f = $M[$i][$k] / $M[$k][$k];
                for ($j = $k; $j <= $n; $j++) {
                    $M[$i][$j] -= $f * $M[$k][$j];
                }
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $s = $M[$i][$n];
            for ($j = $i + 1; $j < $n; $j++) $s -= $M[$i][$j] * $x[$j];
            $x[$i] = $s / $M[$i][$i];
        }
        return $x;
    }
}
