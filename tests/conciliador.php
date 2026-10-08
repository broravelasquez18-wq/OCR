<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $path = dirname(__DIR__) . '/app/' . str_replace('\\','/',substr($class,4)) . '.php';
    if (is_file($path)) require $path;
});
use App\Services\Conciliador;
function comprobar(bool $valor, string $mensaje): void { if (!$valor) throw new RuntimeException($mensaje); }
$base = ['nombre'=>'PÉREZ LOPEZ, MARÍA JOSE','numero_documento'=>'1234567890',
    'numero_leido'=>'1234567890','nombres_ocr'=>'MARIA JOSE','apellidos_ocr'=>'PERES LOPEZ',
    'documento_ocr_id'=>1,'confianza'=>80,'zona_fuente'=>'frontal'];
$resultado = Conciliador::preparar([$base])[0];
comprobar($resultado['conciliacion']['valores']['apellidos'] === 'PÉREZ LOPEZ', 'Corregir una letra con número exacto.');
comprobar($resultado['apellidos_ocr'] === 'PERES LOPEZ', 'Conservar evidencia OCR.');
comprobar($resultado['nombre'] === $base['nombre'], 'Conservar Excel.');
$bajo = array_replace($base,['apellidos_ocr'=>'PEREZ LOPEZ','numero_leido'=>'1234567891','confianza'=>30]);
$r = Conciliador::preparar([$bajo])[0]['conciliacion'];
comprobar($r['valores']['numero'] === '1234567890', 'Proponer número con nombres exactos y OCR débil.');
$r = Conciliador::preparar([array_replace($bajo,['zona_fuente'=>'pdf417','confianza'=>99])])[0]['conciliacion'];
comprobar($r['valores'] === [] && str_contains($r['detalles'][0],'Posible corrección de la lista'), 'PDF417 señala posible error en Excel sin sobrescribirlo.');
$r = Conciliador::preparar([array_replace($bajo,['confianza'=>90])])[0]['conciliacion'];
comprobar($r['valores'] === [] && str_contains($r['detalles'][0],'No hay evidencia'), 'No resolver conflicto sin evidencia.');
foreach (['confirmado'=>1, 'corregido_manual'=>1,'numero_confirmado'=>'1234567890','nombres_manual'=>'OTRO',
    'apellidos_manual'=>'OTRO','texto_crudo'=>'[REVISION OCR] conflicto'] as $campo=>$valor) {
    $r = Conciliador::preparar([array_replace($base,[$campo=>$valor])])[0]['conciliacion'];
    comprobar($r['valores'] === [], 'Preservar manuales/alertas: ' . $campo);
}
$r = Conciliador::preparar([$base,$base]);
comprobar($r[0]['conciliacion']['valores'] === [] && $r[1]['conciliacion']['valores'] === [], 'No corregir duplicados ambiguos.');
$r = Conciliador::preparar([array_replace($base,['nombres_ocr'=>'LUIS ALBERTO'])])[0]['conciliacion'];
comprobar($r['valores'] === [], 'No transformar otra identidad.');
$r = Conciliador::preparar([array_replace($base,['apellidos_ocr'=>null])])[0]['conciliacion'];
comprobar($r['valores'] === [], 'No inventar apellidos faltantes.');
echo "OK: conciliación bidireccional, originales intactos, conflictos y datos manuales protegidos.\n";
