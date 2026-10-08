<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('BASE_PATH',dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
    $file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file)) require $file;
});
App\Core\Env::load(BASE_PATH.'/.env');
$pdo=App\Core\Database::conn();
$pdo->prepare('INSERT INTO lotes_verificacion (codigo_ficha,estado) VALUES (?,?)')->execute(['TEST'.bin2hex(random_bytes(4)),'en_proceso']);
$lote=(int)$pdo->lastInsertId();
try {
    foreach ([['1234567890','ANA MARIA PEREZ LOPEZ',null,'PEREZ LOPEZ'],
              ['2234567890','(sin nombre)','JUAN','GOMEZ'],
              ['3234567890','LUISA LOPEZ',null,null]] as [$num,$nombre,$nombres,$apellidos]) {
        $pdo->prepare('INSERT INTO inscritos(lote_id,tipo_documento,numero_documento,nombre) VALUES (?,"CC",?,?)')->execute([$lote,$num,$nombre]);
        $pdo->prepare('INSERT INTO documentos_ocr(lote_id,origen,ruta_imagen,numero_leido,nombres,apellidos,confianza,texto_crudo) VALUES (?,"pdf","test-sin-imagen",?,?,?,?,"")')->execute([$lote,$num,$nombres,$apellidos,95]);
    }
    foreach ([['4234567890','4234567891','PEDRO','RUIZ',30,'frontal',''],
              ['5234567890','5234567891','MARTA','GOMEZ',99,'codigo_barras','[NUMERO VERIFICADO EN PDF417]']] as [$lista,$leido,$nombre,$apellido,$conf,$zona,$texto]) {
        $pdo->prepare('INSERT INTO inscritos(lote_id,tipo_documento,numero_documento,nombre) VALUES (?,"CC",?,?)')->execute([$lote,$lista,$nombre.' '.$apellido]);
        $pdo->prepare('INSERT INTO documentos_ocr(lote_id,origen,ruta_imagen,numero_leido,nombres,apellidos,confianza,zona_fuente,texto_crudo) VALUES (?,"pdf","test-sin-imagen",?,?,?,?,?,?)')->execute([$lote,$leido,$nombre,$apellido,$conf,$zona,$texto]);
    }
    $n=App\Services\Autocompletador::completar($lote);
    if($n!==5 || App\Services\Autocompletador::completar($lote)!==0) throw new RuntimeException('Autocompletado no idempotente.');
    $s=$pdo->prepare('SELECT numero_leido FROM documentos_ocr WHERE lote_id=? AND nombres="PEDRO"');$s->execute([$lote]);
    if($s->fetchColumn()!=='4234567890') throw new RuntimeException('No se corrigió número OCR.');
    $s=$pdo->prepare('SELECT numero_documento FROM inscritos WHERE lote_id=? AND nombre="MARTA GOMEZ"');$s->execute([$lote]);
    if($s->fetchColumn()!=='5234567891') throw new RuntimeException('No se corrigió número de la lista.');
    $s=$pdo->prepare('SELECT nombre FROM inscritos WHERE lote_id=? AND numero_documento="2234567890"');$s->execute([$lote]);
    if($s->fetchColumn()!=='JUAN GOMEZ') throw new RuntimeException('No se actualizó la lista.');
    $s=$pdo->prepare('SELECT nombres FROM documentos_ocr WHERE lote_id=? AND numero_leido="1234567890"');$s->execute([$lote]);
    if($s->fetchColumn()!=='ANA MARIA') throw new RuntimeException('No se actualizó el documento.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM autocompletados WHERE lote_id=?');$s->execute([$lote]);
    if((int)$s->fetchColumn()!==5) throw new RuntimeException('Auditoría incompleta.');
    (new App\Services\Comparador())->comparar($lote);
    $rows=App\Models\Verificacion::porLoteDetallado($lote);
    foreach($rows as $row) if($row['estado']==='ok') throw new RuntimeException('La copia circular no es validación independiente.');
    echo "OK: datos guardados en ambas direcciones, auditoría, idempotencia y cruce sin validación circular.\n";
} finally {
    $pdo->prepare('DELETE FROM lotes_verificacion WHERE id=?')->execute([$lote]);
}
