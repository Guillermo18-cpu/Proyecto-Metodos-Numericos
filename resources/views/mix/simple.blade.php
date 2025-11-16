<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Calculadora de Mezcla Nutricional</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-green-50 min-h-screen py-10">
    <div class="max-w-5xl mx-auto p-6 bg-white rounded-2xl shadow">
        <h1 class="text-3xl font-bold text-center text-green-700">Calculadora de Mezcla — (3 ingredientes)</h1>
        <p class="text-center text-gray-600 mt-2">Introduce la composición (%) de cada ingrediente o en decimal (ej: 20 o 0.2). Luego pon la meta nutricional.</p>

        <form id="mixForm" class="mt-6 space-y-6" onsubmit="return false;">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Repetir ingrediente 1..3 -->
                @for ($ing = 1; $ing <= 3; $ing++)
                    <div class="p-4 border rounded-lg bg-gray-50">
                    <h3 class="font-semibold mb-2">Ingrediente {{ $ing }}</h3>
                    <label class="text-sm">Proteína</label>
                    <input id="a{{ $ing }}1" class="w-full p-2 border rounded mt-1" placeholder="ej: 48 o 0.48">
                    <label class="text-sm mt-2 block">Carbohidrato</label>
                    <input id="a{{ $ing }}2" class="w-full p-2 border rounded mt-1" placeholder="ej: 30 o 0.30">
                    <label class="text-sm mt-2 block">Grasa</label>
                    <input id="a{{ $ing }}3" class="w-full p-2 border rounded mt-1" placeholder="ej: 9 o 0.09">
            </div>
            @endfor
    </div>

    <div class="mt-2 p-4 border rounded-lg bg-white">
        <h3 class="font-semibold">Objetivo nutricional</h3>
        <div class="grid grid-cols-3 gap-3 mt-3">
            <div>
                <label>Proteína</label>
                <input id="b1" class="w-full p-2 border rounded" placeholder="ej: 20 o 0.2">
            </div>
            <div>
                <label>Carbohidrato</label>
                <input id="b2" class="w-full p-2 border rounded" placeholder="ej: 50 o 0.5">
            </div>
            <div>
                <label>Grasa</label>
                <input id="b3" class="w-full p-2 border rounded" placeholder="ej: 10 o 0.1">
            </div>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-3">
            <input id="tolerance" type="number" step="0.00001" value="0.001" class="border p-2 rounded" />
            <input id="maxIter" type="number" value="200" class="border p-2 rounded" />
            <select id="method" class="border p-2 rounded">
                <option value="auto">Auto (GS→Jacobi→Fallback)</option>
                <option value="gauss">Gauss-Seidel</option>
                <option value="jacobi">Jacobi</option>
            </select>
        </div>

        <div class="mt-4 flex gap-2">
            <button id="btnExample" class="bg-blue-500 text-white px-4 py-2 rounded">Cargar ejemplo</button>
            <button id="btnSolve" class="bg-green-600 text-white px-4 py-2 rounded flex-1">Calcular mezcla óptima</button>
        </div>
        <p class="text-xs text-gray-500 mt-2">Consejo: ingresa porcentajes sin símbolo % (ej: 48 para 48%) o en formato decimal (0.48).</p>
    </div>
    </form>

    <div id="alerts" class="mt-4"></div>

    <div id="result" class="mt-6 hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div id="cardSolution" class="p-4 bg-white border rounded-lg shadow"></div>
            <div id="cardAnalysis" class="p-4 bg-white border rounded-lg shadow"></div>
        </div>
    </div>
    </div>

    <script>
        const token = document.querySelector('meta[name="csrf-token"]').content;

        document.getElementById('btnExample').addEventListener('click', (e) => {
            e.preventDefault();
            // Ejemplo: Soya, maíz, suplemento
            document.getElementById('a11').value = '48'; // proteina
            document.getElementById('a12').value = '30';
            document.getElementById('a13').value = '9';

            document.getElementById('a21').value = '7';
            document.getElementById('a22').value = '74';
            document.getElementById('a23').value = '4';

            document.getElementById('a31').value = '80';
            document.getElementById('a32').value = '10';
            document.getElementById('a33').value = '3';

            document.getElementById('b1').value = '20';
            document.getElementById('b2').value = '50';
            document.getElementById('b3').value = '10';
        });

        function showAlert(msg, color = 'red') {
            const a = document.getElementById('alerts');
            a.innerHTML = `<div class="p-3 rounded ${color==='red' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}">${msg}</div>`;
            setTimeout(() => a.innerHTML = '', 6000);
        }

        document.getElementById('btnSolve').addEventListener('click', async (e) => {
            e.preventDefault();
            // leer inputs
            const A = [
                [document.getElementById('a11').value, document.getElementById('a12').value, document.getElementById('a13').value],
                [document.getElementById('a21').value, document.getElementById('a22').value, document.getElementById('a23').value],
                [document.getElementById('a31').value, document.getElementById('a32').value, document.getElementById('a33').value],
            ];
            const b = [document.getElementById('b1').value, document.getElementById('b2').value, document.getElementById('b3').value];
            const tolerance = document.getElementById('tolerance').value;
            const maxIter = document.getElementById('maxIter').value;
            const method = document.getElementById('method').value;

            // Validación básica
            for (let i = 0; i < 3; i++)
                for (let j = 0; j < 3; j++)
                    if (!A[i][j] || isNaN(Number(A[i][j]))) {
                        showAlert('Rellena todos los coeficientes con números válidos.');
                        return;
                    }
            for (let i = 0; i < 3; i++)
                if (!b[i] || isNaN(Number(b[i]))) {
                    showAlert('Rellena el objetivo nutricional.');
                    return;
                }

            // Preparar body JSON (no stringify A as JSON string; send primitive)
            const payload = {
                a11: A[0][0],
                a12: A[0][1],
                a13: A[0][2],
                a21: A[1][0],
                a22: A[1][1],
                a23: A[1][2],
                a31: A[2][0],
                a32: A[2][1],
                a33: A[2][2],
                b1: b[0],
                b2: b[1],
                b3: b[2],
                tolerance: tolerance,
                maxIter: maxIter,
                method: method
            };

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
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (!res.ok) {
                    const msg = data.message || 'Hubo un error en el servidor.';
                    showAlert(msg);
                    btn.disabled = false;
                    btn.textContent = 'Calcular mezcla óptima';
                    return;
                }

                // Mostrar resultados en tarjetas
                document.getElementById('result').classList.remove('hidden');
                const solCard = document.getElementById('cardSolution');
                const analysis = document.getElementById('cardAnalysis');

                // Formateo
                const raw = data.rawSolution || [];
                const norm = data.normalizedPercent;
                const achieved = data.achievedPerc || [];

                let solHtml = `<h3 class="font-bold text-lg mb-2">Proporciones recomendadas</h3>`;
                if (norm) {
                    solHtml += `<ul class="list-disc pl-5">`;
                    norm.forEach((p, i) => solHtml += `<li>Ingrediente ${i+1}: <strong>${p.toFixed(2)}%</strong></li>`);
                    solHtml += `</ul>`;
                } else {
                    // si no se puede normalizar, mostrar valores crudos
                    solHtml += `<p class="text-sm text-gray-600">No fue posible normalizar (suma = 0). Solución cruda:</p>`;
                    solHtml += `<pre>${JSON.stringify(raw.map(v=>Number(v.toFixed(6))))}</pre>`;
                }
                solCard.innerHTML = solHtml;

                let analysisHtml = `<h4 class="font-semibold">Análisis</h4>`;
                analysisHtml += `<p><strong>Método usado:</strong> ${data.methodUsed}</p>`;
                analysisHtml += `<p><strong>Diagonal dominante:</strong> ${data.isDiagonalDominant ? 'Sí' : 'No'}</p>`;
                analysisHtml += `<p><strong>Convergió:</strong> ${data.converged ? 'Sí' : 'No'}</p>`;
                if (data.iterations) analysisHtml += `<p><strong>Iteraciones:</strong> ${data.iterations}</p>`;
                analysisHtml += `<p><strong>Residuo (||Ax-b||∞):</strong> ${Number(data.residual).toExponential(6)}</p>`;
                if (data.hasNegative) analysisHtml += `<p class="text-red-600"><strong>Atención:</strong> La solución contiene valores negativos — revise los datos de entrada o la factibilidad.</p>`;

                analysisHtml += `<hr class="my-2">`;
                analysisHtml += `<h4 class="font-semibold">Composición lograda (aprox.)</h4>`;
                analysisHtml += `<ul class="list-disc pl-5">`;
                analysisHtml += `<li>Proteína: <strong>${achieved[0] ? achieved[0].toFixed(2) : 'n/a'}%</strong></li>`;
                analysisHtml += `<li>Carbohidrato: <strong>${achieved[1] ? achieved[1].toFixed(2) : 'n/a'}%</strong></li>`;
                analysisHtml += `<li>Grasa: <strong>${achieved[2] ? achieved[2].toFixed(2) : 'n/a'}%</strong></li>`;
                analysisHtml += `</ul>`;

                analysisHtml += `<p class="mt-2 text-sm text-gray-600">Interpretación: si ingresaste A y b en %, el resultado muestra porcentajes. Si usaste decimales (0.2), multiplícalo por 100 para ver %.</p>`;

                analysis.innerHTML = analysisHtml;

            } catch (err) {
                console.error(err);
                showAlert('Error comunicando al servidor.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Calcular mezcla óptima';
            }
        });
    </script>
</body>

</html>