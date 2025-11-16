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
        <h1 class="text-3xl font-bold text-center text-green-700">Calculadora de Mezcla — Gauss-Seidel (3 ingredientes)</h1>
        <p class="text-center text-gray-600 mt-2">Introduce la composición (%) de cada ingrediente y el objetivo nutricional. Se usará el Método de Gauss-Seidel.</p>

        <form id="mixForm" class="mt-6 space-y-6" onsubmit="return false;">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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

                <div class="mt-4 max-w-md mx-auto"> 
                    <h4 class="font-semibold mb-2">Parámetros de Convergencia</h4>
                    <div class="grid grid-cols-2 gap-3"> 
                        <div>
                            <label class="text-sm font-medium block">Tolerancia</label>
                            <input id="tolerance" type="number" step="0.00001" value="0.001" class="border p-2 rounded w-full" />
                            <p class="text-xs text-gray-500 mt-1">Error máximo ($\epsilon$) para detener la iteración (ej: 0.001).</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium block">Iteraciones Máximas</label>
                            <input id="maxIter" type="number" value="30" class="border p-2 rounded w-full" />
                            <p class="text-xs text-gray-500 mt-1">Límite para detener el cálculo si no se alcanza la tolerancia.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <button id="btnExample" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded transition duration-150">Cargar ejemplo</button>
                    <button id="btnSolve" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex-1 transition duration-150">Calcular mezcla óptima</button>
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
        // Obtener el token CSRF
        const token = document.querySelector('meta[name="csrf-token"]').content;

        document.getElementById('btnExample').addEventListener('click', (e) => {
            e.preventDefault();
            
            // --- VALORES OPTIMIZADOS (SOLUCIÓN POSITIVA GARANTIZADA) ---
            // Ingrediente 1: Alta Proteína
            document.getElementById('a11').value = '80'; // Proteína
            document.getElementById('a12').value = '5';  // Carbohidrato
            document.getElementById('a13').value = '5';  // Grasa

            // Ingrediente 2: Alto Carbohidrato
            document.getElementById('a21').value = '10'; // Proteína
            document.getElementById('a22').value = '75'; // Carbohidrato
            document.getElementById('a23').value = '5';  // Grasa

            // Ingrediente 3: Alta Grasa
            document.getElementById('a31').value = '0';  // Proteína
            document.getElementById('a32').value = '5';  // Carbohidrato
            document.getElementById('a33').value = '60'; // Grasa
            
            // Objetivo nutricional
            document.getElementById('b1').value = '20'; // 20% Proteína
            document.getElementById('b2').value = '30'; // 30% Carbohidrato
            document.getElementById('b3').value = '15'; // 15% Grasa
            // -----------------------------------------------------------
        });

        function showAlert(msg, color = 'red') {
            const a = document.getElementById('alerts');
            a.innerHTML = `<div class="p-3 rounded ${color==='red' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}">${msg}</div>`;
            setTimeout(() => a.innerHTML = '', 6000);
        }

        document.getElementById('btnSolve').addEventListener('click', async (e) => {
            e.preventDefault();
            // ... (Lectura de inputs y validación omitida para brevedad, ya está en pasos anteriores) ...
            
            // leer inputs
            const A = [
                [document.getElementById('a11').value, document.getElementById('a12').value, document.getElementById('a13').value],
                [document.getElementById('a21').value, document.getElementById('a22').value, document.getElementById('a23').value],
                [document.getElementById('a31').value, document.getElementById('a32').value, document.getElementById('a33').value],
            ];
            const b = [document.getElementById('b1').value, document.getElementById('b2').value, document.getElementById('b3').value];
            const tolerance = document.getElementById('tolerance').value;
            const maxIter = document.getElementById('maxIter').value;
            const method = 'gauss'; // Fijo en Gauss-Seidel

            // Validación básica (solo números y no vacíos)
            const inputs = [...A.flat(), ...b];
            for (const val of inputs) {
                if (!val || isNaN(Number(val.replace(',', '.')))) {
                    showAlert('Rellena todos los campos con números válidos.');
                    return;
                }
            }


            // Preparar body JSON
            const payload = {
                a11: A[0][0], a12: A[0][1], a13: A[0][2],
                a21: A[1][0], a22: A[1][1], a23: A[1][2],
                a31: A[2][0], a32: A[2][1], a33: A[2][2],
                b1: b[0], b2: b[1], b3: b[2],
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
                if (!res.ok || data.status === 'error') {
                    const msg = (data.message || 'Hubo un error en el servidor.') + (data.messages? '<br>'+Object.values(data.messages).flat().join('<br>') : '');
                    showAlert(msg);
                    return;
                }

                // Mostrar resultados
                document.getElementById('result').classList.remove('hidden');
                const solCard = document.getElementById('cardSolution');
                const analysis = document.getElementById('cardAnalysis');

                const raw = data.rawSolution || [];
                const norm = data.normalizedPercent;
                const achieved = data.achievedPerc || [];

                // Bloque de Solución
                let solHtml = `<h3 class="font-bold text-xl mb-3 text-green-700">Proporciones de la Mezcla</h3>`;

                if (data.hasNegative) {
                    solHtml += `<div class="p-3 bg-red-100 border-l-4 border-red-500 text-red-700 mb-4">
                        ⚠️ La meta es **IMPOSIBLE** con estos ingredientes. La solución contiene cantidades negativas.
                    </div>`;
                    solHtml += `<p class="font-semibold mt-2">Valores Crudos (No Normalizados)</p>`;
                    solHtml += `<ul class="list-disc pl-5 text-red-700">`;
                    raw.forEach((p, i) => solHtml += `<li>Ingrediente ${i+1}: <strong>${p.toFixed(6)}</strong></li>`);
                    solHtml += `</ul>`;
                    solHtml += `<p class="mt-2 text-sm text-gray-600">Esto indica que el sistema de ecuaciones no tiene una solución físicamente posible (una mezcla real). Debe **cambiar la meta** o **usar ingredientes diferentes**.</p>`;

                } else if (norm) {
                    // Solución con explicación en línea
                    solHtml += `<p class="text-sm text-gray-700">Para lograr la composición objetivo, la mezcla debe tener la siguiente proporción por peso:</p>`;
                    solHtml += `<ul class="list-disc pl-5 mt-3 font-bold">`;
                    
                    norm.forEach((p, i) => {
                        const percent = p.toFixed(2) + '%';
                        const weight = p.toFixed(2);
                        solHtml += `<li>Ingrediente ${i+1}: <span class="text-xl text-green-600">${percent}</span> 
                            <span class="text-sm font-normal text-gray-600">— De la mezcla total que prepares, el ${weight}% del peso debe ser del Ingrediente ${i+1}.</span>
                        </li>`;
                    });
                    
                    solHtml += `</ul>`;
                    solHtml += `<p class="mt-4 text-sm text-gray-600">Estos porcentajes suman 100% y representan la proporción de la mezcla total para cada ingrediente.</p>`;

                } else {
                    solHtml += `<div class="p-3 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 mb-4">
                        💡 Advertencia: No fue posible normalizar la solución (la suma de proporciones es cero).
                    </div>`;
                    solHtml += `<p class="font-semibold">Solución Cruda:</p>`;
                    solHtml += `<pre class="text-sm">${JSON.stringify(raw.map(v=>Number(v.toFixed(6))))}</pre>`;
                }
                solCard.innerHTML = solHtml;

                // Bloque de Análisis (Énfasis en la U)
                let analysisHtml = `<h4 class="font-bold text-lg mb-2">Análisis del Método Iterativo (Gauss-Seidel)</h4>`;
                analysisHtml += `<p><strong>Método usado:</strong> ${data.methodUsed}</p>`;
                analysisHtml += `<p><strong>Diagonal dominante:</strong> ${data.isDiagonalDominant ? 'Sí ✔ (Garantiza convergencia)' : 'No ✘ (Convergencia no garantizada)'}</p>`;
                analysisHtml += `<p><strong>Convergió:</strong> ${data.converged ? 'Sí ✔' : 'No ✘'}</p>`;
                analysisHtml += `<p><strong>Iteraciones requeridas:</strong> <strong>${data.iterations}</strong></p>`;
                analysisHtml += `<p><strong>Residuo:</strong> ${Number(data.residual).toExponential(6)}</p>`;
                
                analysisHtml += `<hr class="my-3">`;
                analysisHtml += `<h4 class="font-bold text-blue-700 text-lg mb-2">Composición Lograda (Verificación)</h4>`;
                analysisHtml += `<p class="text-sm text-gray-600">Estos son los nutrientes exactos obtenidos con la solución final:</p>`;
                analysisHtml += `<ul class="list-disc pl-5 mt-2">`;
                achieved.forEach((val, i) => {
                    const nutrientNames = ['Proteína', 'Carbohidrato', 'Grasa'];
                    analysisHtml += `<li>${nutrientNames[i]}: <strong>${val ? val.toFixed(2) : 'n/a'}%</strong></li>`;
                });
                analysisHtml += `</ul>`;

                analysis.innerHTML = analysisHtml;

            } catch (err) {
                console.error(err);
                showAlert('Error comunicando al servidor. Verifica la consola para más detalles.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Calcular mezcla óptima';
            }
        });
    </script>
</body>

</html>