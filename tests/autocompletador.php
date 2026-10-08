<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file)) require $file;
});
use App\Services\Autocompletador as A;
function ok(bool $value,string $why): void {if(!$value) throw new RuntimeException($why);}
$i=['id'=>1,'numero_documento'=>'1234567890','nombre'=>'ANA MARIA PEREZ LOPEZ'];
$d=['id'=>1,'numero_leido'=>'1234567890','nombres'=>null,'apellidos'=>'PEREZ LOPEZ','confianza'=>95,'texto_crudo'=>''];
$ruido=array_replace($d,['nombres'=>'ANA MARIA','apellidos'=>'LOMBIA','texto_crudo'=>"CEDULA DECIUDADANIA\nPEREZ LOPEZ\nLOMBIA\nAPELLIDOS\nANA MARIA\nNOMBRES"]);
$reparado=A::plan([$i],[$ruido]);
ok(($reparado[0]['campos']['apellidos']??null)===['LOMBIA','PEREZ LOPEZ'],'Reparar encabezado con respaldo de OCR y Excel.');
ok(A::plan([$i],[$ruido],[['inscrito_id'=>1,'confirmado'=>1]])===[],'No alterar apellido confirmado.');
ok(A::plan([$i],[array_replace($ruido,['texto_crudo'=>'LOMBIA'])])===[],'No inventar recuperación sin evidencia.');
$p=A::plan([$i],[$d]);
ok($p[0]['campos']['nombres'][1]==='ANA MARIA','Excel completa nombres faltantes.');
$full=array_replace($d,['nombres'=>'ANA MARIA']);
$p=A::plan([array_replace($i,['nombre'=>'(sin nombre)'])],[$full]);
ok($p[0]['destino']==='inscrito' && $p[0]['campos']['nombre'][1]===$i['nombre'],'PDF completa lista sin nombre.');
$p=A::plan([$i],[array_replace($d,['apellidos'=>null])]);
ok(isset($p[0]['campos']['nombre_completo']) && !isset($p[0]['campos']['nombres']),'No adivinar separación de nombre completo.');
ok(A::plan([$i],[array_replace($d,['numero_leido'=>'1234567891'])])===[],'No usar número cercano.');
ok(A::plan([$i],[array_replace($d,['apellidos'=>'OTRA PERSONA'])])===[],'No mezclar identidades.');
ok(A::plan([$i],[$full,array_replace($full,['id'=>2,'nombres'=>'LUIS'])])===[],'Conflictos entre escaneos.');
ok(A::plan([$i],[$d],[['inscrito_id'=>1,'confirmado'=>1]])===[],'Preservar confirmados.');
ok(A::plan([$i],[$d],[['inscrito_id'=>1,'nombres_manual'=>'']])===[],'Preservar vaciado manual.');
ok(A::plan([$i],[$d,array_replace($full,['id'=>2])])[0]['campos']['nombres'][1]==='ANA MARIA','Recuperar separación de otro escaneo.');
ok(A::plan([$i],[array_replace($d,['confianza'=>20])])===[],'No copiar con identificación débil.');
ok(A::plan([$i],[array_replace($d,['texto_crudo'=>'[REVISION OCR] conflicto'])])===[],'No copiar lecturas con alerta.');
ok(A::plan([$i,$i],[$d])===[],'No copiar con identidades duplicadas.');
echo "OK: 12 reglas de autocompletado y protección de originales/manuales.\n";
