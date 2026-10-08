<?php
declare(strict_types=1);
namespace App\Services;

final class RecuperadorCampos
{
    public static function esEncabezado(string $valor): bool
    {
        return in_array(self::compacto($valor), ['COLOMBIA','COLOMBI','LOMBIA','REPUBLICA','REPUBLI','REPUBLICADECOLOMBIA','PURLTCADE'],true);
    }
    private static function compacto(string $s): string
    {
        return preg_replace('/[^\p{L}]/u','',strtr(mb_strtoupper($s),['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'])) ?? '';
    }

    private static function lineas(string $texto): array
    {
        // Las anotaciones agregadas por la aplicación no son evidencia OCR.
        $texto = preg_split('/\[(?:DATOS COMPLETADOS|REVISION OCR)\]/u',$texto)[0];
        return array_values(array_filter(array_map('trim',preg_split('/\R/u',$texto) ?: []),static fn(string $s):bool=>$s!==''));
    }

    public static function nombres(string $lista, string $texto): array
    {
        $etiquetados = self::nombresEtiquetados($lista, $texto);
        if ($etiquetados) return $etiquetados;
        $lineas = array_map([self::class,'compacto'], self::lineas($texto));
        // Formato antiguo: apellidos encima de nombres. No aplicar esta regla
        // de posición a cédulas digitales o documentos de formato desconocido.
        $antigua=in_array('IDENTIFICACIONPERSONAL',$lineas,true) || in_array('CEDULADECIUDADANIA',$lineas,true);
        $digital=(bool)preg_match('/\bNUIP\s*[\d.]+/iu',$texto);
        if (!$antigua && !$digital) return [];
        $lineas = array_values(array_filter($lineas, static fn(string $s):bool=>!self::esEncabezado($s) && !in_array($s,['NOMBRE','NOMBRES','APELLIDO','APELLIDOS'],true)
            && !(strlen($s)>=7 && levenshtein($s,'APELLIDOS')<=2)));
        $palabras=preg_split('/\s+/u',trim($lista),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $candidatos=[];
        for($corte=1;$corte<count($palabras);$corte++) {
            $a=implode(' ',array_slice($palabras,0,$corte)); $b=implode(' ',array_slice($palabras,$corte));
            foreach ([[$a,$b],[$b,$a]] as [$nombres,$apellidos]) {
                for($j=0;$j<count($lineas)-1;$j++) {
                    if($lineas[$j]===self::compacto($apellidos) && $lineas[$j+1]===self::compacto($nombres)) {
                        $candidatos[$nombres.'|'.$apellidos]=['nombres'=>$nombres,'apellidos'=>$apellidos];
                    }
                }
            }
        }
        return count($candidatos)===1 ? reset($candidatos) : [];
    }

    private static function nombresEtiquetados(string $lista, string $texto): array
    {
        $lineas = self::lineas($texto);
        // No buscar nombres en firma, reverso ni anotaciones del sistema.
        foreach ($lineas as $i=>$linea) if (preg_match('/FIRMA|\[REVERSO\]|\[SEGUNDA PAGINA/iu',$linea)) { $lineas=array_slice($lineas,0,$i); break; }
        $digital=(bool)preg_match('/\bNUIP\s*[\d.]+/iu',$texto);
        $valores=['nombres'=>[], 'apellidos'=>[]];
        foreach ($lineas as $i=>$linea) {
            $label=self::compacto($linea);
            if (!in_array($label,['NOMBRE','NOMBRES','APELLIDO','APELLIDOS','APELDIDOS','NOMARES'],true)) continue;
            $campo=str_starts_with($label,'NOM')?'nombres':'apellidos';
            for($offset=1;$offset<=3;$offset++) {
                $s=$lineas[$i+($digital?$offset:-$offset)]??'';
                if ($s==='' || preg_match('/NOMBRES?|APELLIDOS?|NUMERO|NACIONALIDAD/iu',$s)) break;
                // ! se confunde con I al final de una palabra en estas lecturas.
                $s=preg_replace('/(?<=[A-Za-z])!(?=\s|$)/u','I',$s) ?? $s;
                $valores[$campo][]=self::compacto($s);
            }
        }
        $palabras=preg_split('/\s+/u',trim($lista),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $candidatos=[];
        for($corte=1;$corte<count($palabras);$corte++) {
            $a=implode(' ',array_slice($palabras,0,$corte)); $b=implode(' ',array_slice($palabras,$corte));
            foreach ([[$a,$b],[$b,$a]] as [$n,$a]) {
                if(in_array(self::compacto($n),$valores['nombres'],true) && in_array(self::compacto($a),$valores['apellidos'],true)) $candidatos[$n.'|'.$a]=['nombres'=>$n,'apellidos'=>$a];
            }
        }
        return count($candidatos)===1 ? reset($candidatos) : [];
    }

    public static function fecha(string $texto): ?string
    {
        $lineas=self::lineas($texto); $fechas=[];
        $meses=['ENE'=>1,'FEB'=>2,'MAR'=>3,'ABR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AGO'=>8,'SEP'=>9,'SET'=>9,'OCT'=>10,'NOV'=>11,'DIC'=>12];
        foreach($lineas as $i=>$linea) {
            $ancla=self::compacto(preg_replace('/\d.*$/u','',$linea) ?? $linea);
            if(!preg_match('/^FECHA(?:DE)?([A-Z]+)$/',$ancla,$m) || levenshtein($m[1],'NACIMIENTO')>1) continue;
            foreach([$linea,$lineas[$i+1]??'', $lineas[$i-1]??''] as $tramo) {
                if(preg_match('/EXPEDI|VENCIM/i',$tramo)) continue;
                if(!preg_match('/\b(\d{1,2})[\s.\/-]*([A-Z]{3})[\s.\/-]*(\d{4})\b/',mb_strtoupper($tramo),$f)) continue;
                $mes=$meses[$f[2]]??0; $dia=(int)$f[1]; $anio=(int)$f[3];
                if($anio<1900 || !checkdate($mes,$dia,$anio)) continue;
                $fecha=sprintf('%04d-%02d-%02d',$anio,$mes,$dia);
                if($fecha<=date('Y-m-d')) $fechas[$fecha]=true;
            }
        }
        return count($fechas)===1 ? array_key_first($fechas) : null;
    }

    public static function numeroFrontal(string $texto): ?string
    {
        $lineas=self::lineas($texto); $numeros=[];
        foreach($lineas as $i=>$linea) {
            if(preg_match('/NACIM|REVERSO|EXPEDI/iu',$linea)) break;
            $linea=preg_replace('/NUMER0(?=\d)/iu','NUMERO',$linea) ?? $linea;
            if(preg_match('/(?<!\d)(\d{1,3}(?:[.,]\d{3}){2,3})(?!\d)/u',$linea,$m)) {
                $n=preg_replace('/\D/','',$m[1]);
                if(strlen($n)>=6 && strlen($n)<=10) $numeros[$n]=true;
            }
        }
        return count($numeros)===1 ? (string)array_key_first($numeros) : null;
    }
}
