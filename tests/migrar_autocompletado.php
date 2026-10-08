<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('BASE_PATH',dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
    $file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file)) require $file;
});
App\Core\Env::load(BASE_PATH.'/.env');
$pdo=App\Core\Database::conn();
$exists=$pdo->query("SHOW COLUMNS FROM documentos_ocr LIKE 'nombre_completo'")->fetch();
if (!$exists) $pdo->exec('ALTER TABLE documentos_ocr ADD COLUMN nombre_completo VARCHAR(150) NULL');
$sql=file_get_contents(BASE_PATH.'/sql/005_autocompletado.sql');
$pdo->exec(substr($sql,strpos($sql,'CREATE TABLE')));
echo "OK: almacenamiento de autocompletado y trazabilidad disponible.\n";
