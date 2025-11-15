<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Balance de Mezcla Nutricional</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-b from-green-50 to-white min-h-screen py-8">
<div class="max-w-4xl mx-auto p-6 bg-white rounded-2xl shadow">
    <h1 class="text-3xl font-bold text-center text-green-700">Calculadora de Mezcla Nutricional</h1>
    <p class="text-center text-gray-600 mt-2">Ingresa las composiciones de los ingredientes y el objetivo nutricional. El sistema usará métodos numéricos para obtener las proporciones.</p>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="font-semibold">Matriz A (ingredientes × nutrientes)</label>
            <textarea id="matrixA" rows="6" class="mt-1 w-full border rounded p-3" placeholder='Ej: [[0.07,0.82,0.05],[0.45,0.30,0.08],[0.80,0.10,0.03]]'></textarea>
            <p class="text-sm text-gray-500 mt-1">Cada fila = ingrediente. Cada columna = nutriente (ej: proteína, carbohidrato, grasa).</p>
        </div>

        <div>
            <label class="font-semibold">Vector B (objetivo nutricional)</label>
            <textarea id="vectorB" rows="3" class="mt-1 w-full border rounded p-3" placeholder='Ej: [0.20,0.50,0.10]'></textarea>
            <p class="text-sm text-gray-500 mt-1">Ejemplo: 20% proteína, 50% carbohidratos, 10% grasas (en forma decimal o valores absolutos).</p>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <input id="tolerance" type="number" step="0.00001" value="0.001" class="border rounded p-2" />
                <input id="maxIter" type="number" value="50" class="border rounded p-2" />
            </div>
            <div class="mt-2 text-xs text-gray-500 flex justify-between">
                <span>Tolerancia</span><span>Iteraciones max</span>
            </div>

            <div class="mt-3">
                <label class="font-semibold">Método</label>
                <select id="method" class="w-full border rounded p-2 mt-1">
                    <option value="auto">Auto (Gauss-Seidel → Jacobi → Fallback)</option>
                    <option value="gauss">Gauss-Seidel</option>
                    <option value="jacobi">Jacobi</option>
                </select>
            </div>

            <div class="mt-4 flex gap-2">
                <button id="btnExample" class="bg-blue-500 text-white px-3 py-2 rounded">Cargar ejemplo (nutrientes)</button>
                <button id="btnSolve" class="bg-green-600 text-white px-4 py-2 rounded flex-1">Resolver Sistema</button>
            </div>
        </div>
    </div>

    <div id="alerts" class="mt-6"></div>

    <div id="resultBox" class="mt-6 hidden bg-green-50 border border-green-200 p-4 rounded">
        <h3 class="font-bold text-green-700">Resultados</h3>
        <div id="resultContent" class="mt-2 text-gray-800"></div>
    </div>
</div>

<script>
    // CSRF token for fetch
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

    document.getElementById('btnExample').addEventListener('click', () => {
        // ejemplo con porcentajes en decimal
        document.getElementById('matrixA').value = JSON.stringify([
            [0.07, 0.82, 0.05],
            [0.45, 0.30, 0.08],
            [0.80, 0.10, 0.03]
        ]);
        document.getElementById('vectorB').value = JSON.stringify([0.20, 0.50, 0.10]);
    });

    function showAlert(msg, type = 'red') {
        const a = document.getElementById('alerts');
        a.innerHTML = `<div class="p-3 rounded ${type==='red'?'bg-red-100 text-red-700':'bg-yellow-100 text-yellow-700'}">${msg}</div>`;
        setTimeout(()=> a.innerHTML = '', 6000);
    }

    document.getElementById('btnSolve').addEventListener('click', async () => {
        const A = document.getElementById('matrixA').value.trim();
        const b = document.getElementById('vectorB').value.trim();
        const tol = document.getElementById('tolerance').value;
        const maxIter = document.getElementById('maxIter').value;
        const method = document.getElementById('method').value;

        if (!A || !b) {
            showAlert('Por favor completa la matriz A y el vector b primero.', 'red');
            return;
        }

        // Validación rápida JSON cliente
        try {
            JSON.parse(A);
            JSON.parse(b);
        } catch (e) {
            showAlert('Formato JSON inválido. Asegúrate de usar [ ... ] y , separadores.', 'red');
            return;
        }

        // Mostrar loading
        const btn = document.getElementById('btnSolve');
        btn.disabled = true;
        btn.textContent = 'Calculando...';

        try {
            const res = await fetch("{{ route('solver.solve') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    matrixA: A,
                    vectorB: b,
                    tolerance: tol,
                    maxIter: maxIter,
                    method: method
                })
            });

            const data = await res.json();

            if (!res.ok) {
                showAlert((data.error || 'Error en servidor') + (data.messages? '<br>'+data.messages.join('<br>') : ''), 'red');
                btn.disabled = false;
                btn.textContent = 'Resolver Sistema';
                return;
            }

            // Mostrar resultado bonito
            const box = document.getElementById('resultBox');
            const content = document.getElementById('resultContent');
            content.innerHTML = '';

            content.innerHTML += `<p><strong>Diagonal dominance:</strong> ${data.isDiagonalDominant ? 'Sí ✔' : 'No ✘'}</p>`;

            // Mostrar los bloques que existan
            if (data.jacobi) {
                content.innerHTML += `<hr><h4 class="font-semibold">Jacobi</h4>`;
                if (data.jacobi.status === 'ok') {
                    content.innerHTML += formatSolution(data.jacobi.solution, data.jacobi.iterations, data.jacobi.residual);
                } else {
                    content.innerHTML += `<p class="text-red-600">No convergió tras ${data.jacobi.iterations || 'n/a'} iteraciones.</p>`;
                }
            }

            if (data.gaussSeidel) {
                content.innerHTML += `<hr><h4 class="font-semibold">Gauss-Seidel</h4>`;
                if (data.gaussSeidel.status === 'ok') {
                    content.innerHTML += formatSolution(data.gaussSeidel.solution, data.gaussSeidel.iterations, data.gaussSeidel.residual);
                } else {
                    content.innerHTML += `<p class="text-red-600">No convergió tras ${data.gaussSeidel.iterations || 'n/a'} iteraciones.</p>`;
                }
            }

            if (data.fallback) {
                content.innerHTML += `<hr><h4 class="font-semibold">Método directo (Fallback)</h4>`;
                content.innerHTML += `<p><strong>Solución:</strong> ${JSON.stringify(data.fallback.solution)}</p>`;
            }

            box.classList.remove('hidden');

        } catch (err) {
            console.error(err);
            showAlert('Error en la comunicación con el servidor.', 'red');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Resolver Sistema';
        }
    });

    function formatSolution(sol, iter, resid) {
        let s = `<p><strong>Solución (x):</strong> ${JSON.stringify(sol.map(v => Number(v.toFixed(6))))}</p>`;
        s += `<p><strong>Iteraciones:</strong> ${iter}</p>`;
        s += `<p><strong>Residuo (||Ax-b||∞):</strong> ${Number(resid).toExponential(6)}</p>`;
        // Interpretación en kg (si los coeficientes son porcentajes la interpretación depende del usuario)
        s += `<p class="mt-2 text-sm text-gray-600">Interpretación: los valores x son proporciones o kg de cada ingrediente (según la unidad en que ingresaste A y b).</p>`;
        return s;
    }
</script>
</body>
</html>
