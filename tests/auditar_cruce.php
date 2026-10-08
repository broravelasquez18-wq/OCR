<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
App\Core\Env::load(BASE_PATH . '/.env');
$lote = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$lote) throw new RuntimeException('Indica el ID del lote. Solo lectura.');
$inscritos = App\Models\Inscrito::porLote($lote);
$documentos = App\Models\DocumentoOcr::porLote($lote);
$existentes = App\Models\Verificacion::porLoteDetallado($lote);
$resultados = (new App\Services\Comparador())->resolver($inscritos, $documentos, $existentes);
$recalculadas = (new App\Services\Comparador())->resolver($inscritos, $documentos);
echo json_encode(['inscritos'=>count($inscritos), 'documentos'=>count($documentos),
    'con_revision_manual_preservada'=>array_count_values(array_column($resultados,'estado')),
    'simulacion_solo_automatico'=>array_count_values(array_column($recalculadas,'estado')),
    'modificaciones_en_bd'=>0], JSON_UNESCAPED_UNICODE) . "\n";
