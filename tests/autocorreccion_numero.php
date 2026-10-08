<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file)) require $file;
});
use App\Services\Autocompletador as A;
function ok(bool $v,string $m):void {if(!$v) throw new RuntimeException($m);}
$i=['id'=>1,'nombre'=>'ANA MARIA PEREZ LOPEZ','numero_documento'=>'1234567890'];
$d=['id'=>1,'nombres'=>'ANA MARIA','apellidos'=>'PEREZ LOPEZ','numero_leido'=>'1234567891','confianza'=>30,'zona_fuente'=>'frontal','texto_crudo'=>''];
$p=A::plan([$i],[$d]);
ok($p[0]['campos']['numero_leido']===['1234567891','1234567890'],'Corregir OCR débil por nombre exacto.');
$fuerte=array_replace($d,['zona_fuente'=>'codigo_barras','confianza'=>99,'texto_crudo'=>'[NUMERO VERIFICADO EN PDF417]']);
$p=A::plan([$i],[$fuerte]);
ok($p[0]['campos']['numero_documento']===['1234567890','1234567891'],'Corregir lista con código decodificado.');
ok(A::plan([$i],[array_replace($fuerte,['texto_crudo'=>'P-serial-impreso'])])===[],'Serial impreso no equivale a código verificado.');
ok(A::plan([$i],[array_replace($d,['confianza'=>95])])===[],'Confianza alta contradictoria no decide.');
ok(A::plan([$i],[array_replace($d,['numero_leido'=>'9876543210'])])===[],'No reemplazar números totalmente diferentes.');
ok(A::plan([$i,array_replace($i,['id'=>2,'numero_documento'=>'9876543210'])],[$d])===[],'Homónimos.');
ok(A::plan([$i,array_replace($i,['id'=>2,'nombre'=>'OTRA PERSONA','numero_documento'=>'1234567891'])],[$fuerte])===[],'Número pertenece a otra persona.');
ok(A::plan([$i],[$d,array_replace($d,['id'=>2,'numero_leido'=>'1234567892'])])===[],'Escaneos contradictorios.');
ok(A::plan([$i],[array_replace($d,['texto_crudo'=>'[DATOS COMPLETADOS] previo'])])===[],'No reutilizar copia como evidencia.');
ok(A::plan([$i],[$d],[['inscrito_id'=>1,'confirmado'=>1]])===[],'No tocar confirmación manual.');
$p=A::plan([$i],[array_replace($d,['numero_leido'=>null])]);
ok($p[0]['campos']['numero_leido'][1]==='1234567890','Completar número ausente.');
ok(A::plan([$i],[$fuerte,array_replace($fuerte,['id'=>2,'nombres'=>'OTRO'])])===[],'Número OCR compartido por identidades distintas.');
echo "OK: 12 casos de autocorrección de números, procedencia y conflictos.\n";
