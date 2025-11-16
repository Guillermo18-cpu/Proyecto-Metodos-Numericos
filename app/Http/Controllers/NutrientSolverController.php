<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NutrientSolverController extends Controller
{
    public function index()
    {
        return view('mix.simple'); // ver archivo blade más abajo
    }

    public function solve(Request $request)
    {
        // Validación mínima (los campos vienen del formulario)
        $request->validate([
            'a11'=>'required','a12'=>'required','a13'=>'required',
            'a21'=>'required','a22'=>'required','a23'=>'required',
            'a31'=>'required','a32'=>'required','a33'=>'required',
            'b1'=>'required','b2'=>'required','b3'=>'required',
            'tolerance'=>'nullable|numeric',
            'maxIter'=>'nullable|integer'
        ]);

        // Leer y convertir valores (si el usuario escribe 20 se interpreta como 20% -> 0.20)
        $A = [
            [$this->toFraction($request->a11), $this->toFraction($request->a12), $this->toFraction($request->a13)],
            [$this->toFraction($request->a21), $this->toFraction($request->a22), $this->toFraction($request->a23)],
            [$this->toFraction($request->a31), $this->toFraction($request->a32), $this->toFraction($request->a33)],
        ];
        $b = [
            $this->toFraction($request->b1),
            $this->toFraction($request->b2),
            $this->toFraction($request->b3),
        ];

        $tol = $request->input('tolerance', 0.001);
        $maxIter = $request->input('maxIter', 200);

        // Check square and sizes
        if (count($A) !== 3 || count($A[0]) !== 3) {
            return $this->jsonError('La matriz A debe ser 3x3.');
        }
        if (count($b) !== 3) {
            return $this->jsonError('El vector objetivo debe tener 3 valores.');
        }

        // Diagonal dominance check (informativo)
        $isDiag = $this->isDiagonallyDominant($A);

        // Ejecutar Gauss-Seidel
        $gs = $this->gaussSeidel($A, $b, $tol, $maxIter);

        // Ejecutar Jacobi también (informativo)
        $jac = $this->jacobi($A, $b, $tol, $maxIter);

        // Si ninguno converge usar fallback directo
        if (($gs['status'] ?? '') !== 'ok' && ($jac['status'] ?? '') !== 'ok') {
            $fallbackSolution = $this->gaussianElimination($A, $b);
            $methodUsed = 'Gaussian elimination (fallback)';
            $solution = $fallbackSolution;
            $converged = true;
            $iterations = null;
            $residual = $this->residualInf($A, $solution, $b);
        } else {
            // Preferir Gauss-Seidel si convergió
            if (($gs['status'] ?? '') === 'ok') {
                $methodUsed = 'Gauss-Seidel';
                $solution = $gs['solution'];
                $converged = true;
                $iterations = $gs['iterations'];
                $residual = $gs['residual'];
            } else {
                $methodUsed = 'Jacobi';
                $solution = $jac['solution'];
                $converged = true;
                $iterations = $jac['iterations'];
                $residual = $jac['residual'];
            }
        }

        // Interpretación y validaciones finales
        // Si solución contiene negativos -> advertencia (puede ser físicamente inviable)
        $hasNegative = false;
        foreach ($solution as $v) if ($v < -1e-12) { $hasNegative = true; break; }

        // Normalizar solución a porcentajes (si la suma es cero o negativa, no normalizar)
        $sum = array_sum($solution);
        $normalizedPerc = null;
        if ($sum > 1e-12) {
            $normalizedPerc = array_map(function($v) use ($sum){
                return ($v / $sum) * 100.0; // en %
            }, $solution);
        }

        // Composición lograda: A * solution
        $achieved = $this->matVecMul($A, $solution); // en decimales (0..1)
        // Convertir a % para mostrar
        $achievedPerc = array_map(function($v){ return $v * 100.0; }, $achieved);

        // Responder JSON (la vista usa fetch/ajax)
        return response()->json([
            'status' => 'ok',
            'methodUsed' => $methodUsed,
            'isDiagonalDominant' => $isDiag,
            'converged' => $converged,
            'iterations' => $iterations,
            'residual' => $residual,
            'rawSolution' => $solution,
            'normalizedPercent' => $normalizedPerc, // puede ser null si suma ~ 0
            'hasNegative' => $hasNegative,
            'achievedPerc' => $achievedPerc,
            'A' => $A,
            'b' => $b
        ]);
    }

    /* ---------- helpers numeric ---------- */

    // Si el usuario pone 20 -> 0.20; 0.2 -> 0.2
    private function toFraction($v)
    {
        $v = str_replace(',', '.', trim($v));
        if (!is_numeric($v)) throw new \Exception("Valor no numérico: $v");
        $f = floatval($v);
        if ($f > 1.0) return $f / 100.0;
        return $f;
    }

    private function isDiagonallyDominant($A)
    {
        $n = count($A);
        for ($i = 0; $i < $n; $i++) {
            $diag = abs($A[$i][$i]);
            $sum = 0.0;
            for ($j = 0; $j < $n; $j++) if ($j !== $i) $sum += abs($A[$i][$j]);
            if ($diag < $sum) return false;
        }
        return true;
    }

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
            if ($err <= $tol) {
                return ['status'=>'ok','solution'=>$x,'iterations'=>$k,'residual'=>$err];
            }
        }
        return ['status'=>'no_converge','iterations'=>$maxIter];
    }

    private function gaussSeidel($A, $b, $tol, $maxIter)
    {
        $n = count($A);
        $x = array_fill(0, $n, 0.0);
        for ($k = 1; $k <= $maxIter; $k++) {
            for ($i = 0; $i < $n; $i++) {
                $sum = 0.0;
                for ($j = 0; $j < $n; $j++) if ($j !== $i) $sum += $A[$i][$j] * $x[$j];
                $x[$i] = ($b[$i] - $sum) / $A[$i][$i];
            }
            $err = $this->residualInf($A, $x, $b);
            if ($err <= $tol) {
                return ['status'=>'ok','solution'=>$x,'iterations'=>$k,'residual'=>$err];
            }
        }
        return ['status'=>'no_converge','iterations'=>$maxIter];
    }

    private function gaussianElimination($A, $b)
    {
        $n = count($A);
        $M = [];
        for ($i = 0; $i < $n; $i++) {
            $M[$i] = $A[$i];
            $M[$i][] = $b[$i];
        }

        for ($k = 0; $k < $n; $k++) {
            // pivot
            $maxRow = $k;
            $maxVal = abs($M[$k][$k]);
            for ($r = $k+1; $r < $n; $r++) {
                if (abs($M[$r][$k]) > $maxVal) { $maxVal = abs($M[$r][$k]); $maxRow = $r; }
            }
            if ($maxRow !== $k) {
                $tmp = $M[$k]; $M[$k] = $M[$maxRow]; $M[$maxRow] = $tmp;
            }
            // eliminate
            for ($i = $k+1; $i < $n; $i++) {
                if ($M[$k][$k] == 0) continue;
                $f = $M[$i][$k] / $M[$k][$k];
                for ($j = $k; $j <= $n; $j++) $M[$i][$j] -= $f * $M[$k][$j];
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($i = $n-1; $i >= 0; $i--) {
            $s = $M[$i][$n];
            for ($j = $i+1; $j < $n; $j++) $s -= $M[$i][$j] * $x[$j];
            $x[$i] = $s / $M[$i][$i];
        }
        return $x;
    }

    private function matVecMul($A, $x)
    {
        $n = count($A);
        $y = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $s = 0.0;
            for ($j = 0; $j < $n; $j++) $s += $A[$i][$j] * $x[$j];
            $y[$i] = $s;
        }
        return $y;
    }

    private function jsonError($msg, $code = 400)
    {
        return response()->json(['status'=>'error','message'=>$msg], $code);
    }
}
