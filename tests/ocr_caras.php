<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/OcrService.php';
require dirname(__DIR__) . '/app/Services/Edad.php';
require dirname(__DIR__) . '/app/Services/Identidad.php';

use App\Services\OcrService;

$frente = ['numero' => '1234567890', 'zona' => 'frontal', 'nombres' => 'PRUEBA',
    'apellidos' => 'EJEMPLO', 'fecha_nacimiento' => null, 'texto_crudo' => 'frente'];
$reverso = ['numero' => '1234567890', 'zona' => 'mrz', 'nombres' => null,
    'apellidos' => null, 'fecha_nacimiento' => '2000-01-01', 'fecha_zona' => 'mrz', 'texto_crudo' => 'reverso'];
$resultado = OcrService::combinarCaras($frente, $reverso);
if ($resultado['nombres'] !== 'PRUEBA' || $resultado['fecha_nacimiento'] !== '2000-01-01') {
    throw new RuntimeException('No se combinaron los datos de las dos caras.');
}
$sinNumero = array_replace($reverso, ['numero'=>null]);
$resultado = OcrService::combinarCaras($frente, $sinNumero);
if ($resultado['numero'] !== $frente['numero'] || $resultado['fecha_nacimiento'] !== '2000-01-01') throw new RuntimeException('Reverso sin numero no complementa el frente');
foreach ([
    array_replace($reverso, ['numero'=>'9876543210']),
    array_replace($reverso, ['nombres'=>'OTRA PERSONA']),
    array_replace($reverso, ['apellidos'=>'DISTINTO']),
] as $contradictorio) {
    $fallo = false;
    try { OcrService::combinarCaras($frente,$contradictorio); } catch (RuntimeException $e) { $fallo=true; }
    if (!$fallo) throw new RuntimeException('Se mezclaron caras contradictorias');
}
$fallo = false;
try { OcrService::combinarCaras($frente + ['fecha_zona'=>'frontal'], array_replace($reverso,['fecha_nacimiento'=>'2001-01-01'])); } catch (RuntimeException $e) { $fallo=true; }
// Una sola fecha disponible es válida; dos distintas deben rechazarse.
if ($fallo) throw new RuntimeException('Se rechazo una fecha complementaria');
try { OcrService::combinarCaras(array_replace($frente,['fecha_nacimiento'=>'2000-01-01']),array_replace($reverso,['fecha_nacimiento'=>'2001-01-01'])); } catch (RuntimeException $e) { $fallo=true; }
if (!$fallo) throw new RuntimeException('Se mezclaron fechas contradictorias');
$reverso['numero'] = '9876543210';
$reverso['zona'] = 'frontal';
$reverso['nombres'] = 'OTRA PERSONA';
$rechazado = false;
try {
    OcrService::combinarCaras($frente, $reverso);
} catch (RuntimeException $e) {
    $rechazado = true;
}
if (!$rechazado) throw new RuntimeException('Se mezclaron identidades distintas.');
echo "OK: unión de campos y rechazo de identidades incompatibles.\n";
