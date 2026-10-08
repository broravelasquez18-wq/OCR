<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('BASE_PATH',dirname(__DIR__));define('STORAGE_PATH',BASE_PATH.'/storage');
require BASE_PATH.'/vendor/autoload.php';
App\Core\Env::load(BASE_PATH.'/.env');
$source=(int)($argv[1]??0);
if($source<1) throw new RuntimeException('Indica el lote de referencia');
$db=App\Core\Database::conn();
$q=$db->prepare('SELECT * FROM documentos_ocr WHERE lote_id=? ORDER BY pagina,id');$q->execute([$source]);$docs=$q->fetchAll();
if(!$docs) throw new RuntimeException('No hay documentos de referencia');
$inscritos=App\Models\Inscrito::porLote($source);
$db->prepare('INSERT INTO lotes_verificacion(codigo_ficha) VALUES (?)')->execute(['QA'.bin2hex(random_bytes(6))]);
$lote=(int)$db->lastInsertId();$report=[];
try {
    foreach($inscritos as $i) App\Models\Inscrito::crear($lote,$i['tipo_documento'],$i['numero_documento'],$i['nombre'],$i['estado_sena']);
    $ids=[];
    foreach($docs as $i=>$d) {
        $r=(new App\Services\OcrService())->procesar(STORAGE_PATH.'/'.$d['ruta_imagen']);
        $data=['origen'=>'pdf','ruta_imagen'=>$d['ruta_imagen'],'pagina'=>$d['pagina'],
            'numero_leido'=>$r['numero'],'nombres'=>$r['nombres'],'apellidos'=>$r['apellidos'],
            'fecha_nacimiento'=>$r['fecha_nacimiento'],'fecha_zona'=>$r['fecha_zona'],'zona_fuente'=>$r['zona'],
            'confianza'=>$r['confianza'],'texto_crudo'=>$r['texto_crudo']];
        $ids[]=App\Models\DocumentoOcr::crear($lote,$data);
        $report[]=['original_id'=>$d['id'],'lectura'=>$data];
        echo 'OCR '.($i+1).'/'.count($docs)."\n";
    }
    $pares=App\Services\AgrupadorPdf::agrupar($lote,$ids);
    $resumen=(new App\Services\Comparador())->comparar($lote);
    $sobrantes=App\Models\DocumentoOcr::sobrantes($lote);
    // La lectura repetida de Verificación no debe deshacer asociaciones.
    (new App\Services\Comparador())->comparar($lote);
    if(count(App\Models\DocumentoOcr::sobrantes($lote))!==count($sobrantes)) throw new RuntimeException('La segunda comparación cambia los sobrantes');
    $destino=STORAGE_PATH.'/tmp/regresion_ocr_lote'.$source.'.json';
    file_put_contents($destino,json_encode(['lecturas'=>$report,'pares'=>$pares,'resumen'=>$resumen,'sobrantes'=>$sobrantes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    echo json_encode(['paginas'=>count($docs),'pares'=>$pares,'resumen'=>$resumen,'sin_asociar'=>count($sobrantes),'paginas_sin_asociar'=>array_column($sobrantes,'pagina')])."\n";
    if($sobrantes) throw new RuntimeException('Quedan imágenes sin asociar; consultar reporte privado');
} finally {
    // Solo borrar el lote temporal creado por esta ejecución; las imágenes y
    // el lote de referencia nunca se modifican.
    $db->prepare('DELETE FROM lotes_verificacion WHERE id=?')->execute([$lote]);
}
