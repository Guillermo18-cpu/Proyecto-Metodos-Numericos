<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NutrientSolverController extends Controller
{
    public function index()
    {
        // Se usará el mix.simple.blade.php modificado en el siguiente paso
        return view('mix.simple'); 
    }

    public function solve(Request $request)
    {
        // Validación mínima
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

        if (count($A) !== 3 || count($A[0]) !== 3 || count($b) !== 3) {
            return $this->jsonError('La matriz debe ser 3x3 y el vector 3x1.');
        }

        // --- MÉTODO ÚNICO: GAUSS-SEIDEL ---
        $isDiag = $this->isDiagonallyDominant($A);
        $result = $this->gaussSeidel($A, $b, $tol, $maxIter);

        $methodUsed = 'Gauss-Seidel (Método Iterativo)';
        $converged = $result['status'] === 'ok';
        $solution = $converged ? $result['solution'] : array_fill(0, 3, 0.0); // Retorna ceros si no converge
        $iterations = $result['iterations'] ?? $maxIter;
        $residual = $converged ? $result['residual'] : $this->residualInf($A, $solution, $b); // Calcula residual si convergió
        // ----------------------------------

        // Si no converge, lanzamos un error que el frontend pueda manejar
        if (!$converged) {
             return $this->jsonError('El método Gauss-Seidel no convergió después de ' . $maxIter . ' iteraciones. Intenta cambiar la tolerancia o los ingredientes.', 400);
        }

        // Interpretación y validaciones finales (como antes)
        $hasNegative = false;
        foreach ($solution as $v) if ($v < -1e-12) { $hasNegative = true; break; }

        $sum = array_sum($solution);
        $normalizedPerc = null;
        if ($sum > 1e-12) {
            $normalizedPerc = array_map(function($v) use ($sum){
                return ($v / $sum) * 100.0;
            }, $solution);
        }

        $achieved = $this->matVecMul($A, $solution);
        $achievedPerc = array_map(function($v){ return $v * 100.0; }, $achieved);

        // Respuesta JSON
        return response()->json([
            'status' => 'ok',
            'methodUsed' => $methodUsed,
            'isDiagonalDominant' => $isDiag,
            'converged' => $converged,
            'iterations' => $iterations,
            'residual' => $residual,
            'rawSolution' => $solution,
            'normalizedPercent' => $normalizedPerc,
            'hasNegative' => $hasNegative,
            'achievedPerc' => $achievedPerc,
        ]);
    }

    /* ---------- helpers numeric (Se mantienen) ---------- */

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
            // Estricta dominancia diagonal (condición suficiente, no necesaria)
            if ($diag <= $sum) return false;
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

    private function gaussSeidel($A, $b, $tol, $maxIter)
    {
        $n = count($A);
        $x = array_fill(0, $n, 0.0);
        for ($k = 1; $k <= $maxIter; $k++) {
            $xOld = $x; // Guardar la solución de la iteración anterior para calcular el error
            for ($i = 0; $i < $n; $i++) {
                $sum = 0.0;
                for ($j = 0; $j < $n; $j++) if ($j !== $i) $sum += $A[$i][$j] * $x[$j];
                $x[$i] = ($b[$i] - $sum) / $A[$i][$i];
            }
            
            // Error de convergencia: se calcula como la norma-infinito de la diferencia entre xNew y xOld
            $err = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $diff = abs($x[$i] - $xOld[$i]);
                if ($diff > $err) $err = $diff;
            }

            if ($err <= $tol) {
                // Aquí calculamos el residuo final para ser más precisos con Ax-b
                $residual = $this->residualInf($A, $x, $b); 
                return ['status'=>'ok','solution'=>$x,'iterations'=>$k,'residual'=>$residual];
            }
        }
        // No convergió
        return ['status'=>'no_converge','iterations'=>$maxIter];
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