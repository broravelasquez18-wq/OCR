<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
define('BASE_PATH',dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
    $file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file)) require $file;
});
App\Core\Env::load(BASE_PATH.'/.env');
$id=filter_var($argv[1]??null,FILTER_VALIDATE_INT);
if(!$id) throw new RuntimeException('Indica lote; --aplicar para guardar.');
if(in_array('--aplicar',$argv,true)) {
    echo json_encode(['registros_completados'=>App\Services\Autocompletador::completar($id)])."\n";
} else {
    $plan=App\Services\Autocompletador::plan(App\Models\Inscrito::porLote($id),App\Models\DocumentoOcr::porLote($id),App\Models\Verificacion::porLoteDetallado($id));
    echo json_encode(['registros_a_completar'=>count($plan),'destinos'=>array_count_values(array_column($plan,'destino'))])."\n";
}
