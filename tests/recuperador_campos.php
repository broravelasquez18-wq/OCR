<?php
declare(strict_types=1);
spl_autoload_register(static function(string $c):void{$p=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require $p;});
use App\Services\RecuperadorCampos as R;
function check(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);}
$texto="IDENTIFICACION PERSONAL\n1.234.567.890\nPEREZLOPEZ\nANA\nFECHADE ACIMIENTO\n19-DIC-2000\n30-ENE-2019 CIUDAD\nFECHAYLUGARDEEXPEDICION";
check(R::nombres('RODRIGO POSADA ALFONSO',"NUMERO 96.333.300\nPOSADA ALFONSO\nAPELLIDOS\nCAD\nRODRIGO\nCOLOMBI\nNOMBRES")===['nombres'=>'RODRIGO','apellidos'=>'POSADA ALFONSO'],'Ruido entre etiquetas.');
check(R::nombres('LEONEL OYOLA SABI',"17711201\nNUMERO\nOYOLASAB!\nAPELLIDOS\nLEONEL\nNOMBRES\nBeohglpuoot\nFIRMA")===['nombres'=>'LEONEL','apellidos'=>'OYOLA SABI'],'No tomar firma como nombre.');
check(R::nombres('ANA PEREZ',"NUMERO\nOTRO\nAPELLIDOS\nOTRA\nNOMBRES\nFIRMA\nPEREZ\nAPELLIDOS\nANA\nNOMBRES")===[],'No usar etiquetas tras firma.');
check(R::nombres('ANA PEREZ LOPEZ',$texto)===['nombres'=>'ANA','apellidos'=>'PEREZ LOPEZ'],'Separar por evidencia de líneas, no por número de palabras.');
check(R::nombres('PEREZ LOPEZ ANA',$texto)===['nombres'=>'ANA','apellidos'=>'PEREZ LOPEZ'],'Admitir orden inverso en Excel.');
check(R::nombres('ANA PEREZ LOPEZ','[DATOS COMPLETADOS] ANA PEREZ LOPEZ')===[],'No usar anotaciones como evidencia.');
check(R::nombres('LUIS PEREZ LOPEZ',$texto)===[],'No inventar nombre.');
check(R::nombres('ANA PEREZ LOPEZ',str_replace('IDENTIFICACION PERSONAL','CEDULA DIGITAL',$texto))===[],'No aplicar posición antigua a otros formatos.');
check(R::fecha($texto)==='2000-12-19','Fecha con etiqueta dañada.');
check(R::fecha("FECHA DE EXPEDICION\n19-DIC-2000")===null,'No confundir expedición.');
check(R::fecha("FECHA DE NACIMIENTO\n31-FEB-2000")===null,'Fecha imposible.');
check(R::fecha("FECHA DE NACIMIENTO\n01-ENE-2000\nX\nFECHA DE NACIMIENTO\n02-FEB-2000")===null,'Fechas contradictorias.');
check(R::nombres('ANA MARIA PEREZ LOPEZ',"NUIP1.234.567.890\nApellidos\nPEREZLOPEZ\nANAMARIA")===['nombres'=>'ANA MARIA','apellidos'=>'PEREZ LOPEZ'],'Digital y espacios perdidos.');
check(R::fecha('FECHA DE NACIMIENTO 08-JUL-1994')==='1994-07-08','Fecha en misma línea.');
check(R::numeroFrontal("NUMER01.234.567.890\n[REVERSO]\n9.876.543.210")==='1234567890','Número frontal aislado del reverso.');
echo "OK: 12 casos de recuperación con evidencia y rechazo de ambigüedades.\n";
