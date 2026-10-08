<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
use App\Services\Comparador;
use App\Services\Diagnostico;
$comparador = new Comparador();
$ins = ['id'=>1, 'nombre'=>'MARIA JOSE PEREZ LOPEZ', 'numero_documento'=>'1234567890'];
$doc = ['id'=>10, 'numero_leido'=>'1234567890', 'nombres'=>'MARÍA JOSÉ', 'apellidos'=>'PÉREZ LÓPEZ',
    'confianza'=>95, 'fecha_nacimiento'=>'2000-01-01', 'texto_crudo'=>'', 'zona_fuente'=>'frontal'];
function verificar(bool $ok, string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); }
function estado(array $ins, array $docs): string { return (new Comparador())->resolver([$ins], $docs)[0]['estado']; }
verificar(estado($ins, [$doc]) === 'ok', 'Número y nombre concordantes.');
verificar(estado(array_replace($ins,['nombre'=>'PEREZ LOPEZ, MARIA JOSE']),[$doc]) === 'ok', 'Orden de columnas/nombre no debe impedir el cruce.');
verificar(estado($ins, [array_replace($doc,['nombres'=>'LUIS ALBERTO'])]) === 'no_coincide', 'Número exacto con otra persona no es OK.');
verificar(estado($ins, [array_replace($doc,['apellidos'=>null])]) === 'no_coincide', 'Campos ausentes necesitan revisión.');
verificar(estado($ins, [array_replace($doc,['confianza'=>20])]) === 'no_coincide', 'Baja confianza necesita revisión.');
verificar(estado($ins, [array_replace($doc,['texto_crudo'=>'[REVISION OCR] Lecturas distintas'])]) === 'no_coincide', 'No ignorar alertas.');
$cercano = array_replace($doc,['numero_leido'=>'1234567891', 'apellidos'=>'PERES LOPEZ']);
$filas = $comparador->resolver([$ins], [$cercano]);
verificar($filas[0]['estado'] === 'no_coincide' && $filas[0]['documento_ocr_id'] === 10, 'Un error de letra y de dígito permite propuesta, nunca OK.');
verificar(estado($ins,[array_replace($cercano,['nombres'=>'LUIS ALBERTO'])]) === 'falta_documento', 'Número parecido sin nombres concordantes no asigna.');
$doble = $comparador->resolver([$ins, array_replace($ins,['id'=>2])],[$doc]);
verificar($doble[0]['documento_ocr_id'] === null && $doble[1]['documento_ocr_id'] === null, 'Duplicados del Excel son ambiguos.');
$empate = $comparador->resolver([$ins],[$cercano,array_replace($cercano,['id'=>11,'numero_leido'=>'1234567892'])]);
verificar($empate[0]['documento_ocr_id'] === null, 'Empates no se asignan por orden.');
$mejor = $comparador->resolver([$ins],[$doc,array_replace($doc,['id'=>11,'nombres'=>'LUIS','confianza'=>99])]);
verificar($mejor[0]['documento_ocr_id'] === 10, 'Nombre concordante prima sobre confianza mayor de otra persona.');
$manual = $comparador->resolver([$ins],[$doc],[['inscrito_id'=>1,'confirmado'=>1,'corregido_manual'=>0,
    'numero_confirmado'=>'1234567899','documento_ocr_id'=>42,'estado'=>'corregido']]);
verificar($manual[0]['documento_ocr_id'] === 42 && $manual[0]['estado'] === 'corregido', 'Conservar confirmaciones manuales.');
$diag = Diagnostico::analizar(['estado'=>'no_coincide','numero_documento'=>'1234567890',
    'numero_leido'=>'1234567890','nombre'=>$ins['nombre'],'nombres_ocr'=>'LUIS','apellidos_ocr'=>'OTRO',
    'confianza'=>95, 'documento_ocr_id'=>10]);
verificar(str_contains($diag['detalle'],'nombres y apellidos difieren'), 'Mostrar el motivo real de revisión.');
$repetido = array_replace($doc,['numero_leido'=>'1234567891']);
$repeticiones = $comparador->resolver([$ins],[$repetido, array_replace($repetido,['id'=>11])]);
verificar($repeticiones[0]['estado'] === 'no_coincide' && $repeticiones[0]['documento_ocr_id'] === 11, 'Repetir escaneo no debe ocultar propuesta por nombre.');
echo "OK: 14 casos de identidad, ambigüedad, propuestas y preservación de revisión manual.\n";
