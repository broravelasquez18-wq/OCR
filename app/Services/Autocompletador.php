<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;

final class Autocompletador
{
    public static function vacio(?string $valor): bool
    {
        return trim((string)$valor) === '' || mb_strtolower(trim((string)$valor)) === '(sin nombre)';
    }

    /** Plan puro para probar las reglas sin modificar datos. */
    public static function plan(array $inscritos, array $documentos, array $verificaciones = []): array
    {
        $protegidos = []; $docsProtegidos = []; $cuentas = [];
        foreach ($verificaciones as $v) {
            if (!empty($v['confirmado']) || !empty($v['corregido_manual']) || ($v['numero_confirmado'] ?? null) !== null
                || ($v['nombres_manual'] ?? null) !== null || ($v['apellidos_manual'] ?? null) !== null) {
                $protegidos[(int)$v['inscrito_id']] = true;
                if (!empty($v['documento_ocr_id'])) $docsProtegidos[(int)$v['documento_ocr_id']] = true;
            }
        }
        foreach ($inscritos as $ins) $cuentas[(string)$ins['numero_documento']] = ($cuentas[(string)$ins['numero_documento']] ?? 0) + 1;
        $plan = [];
        foreach ($inscritos as $ins) {
            $numero = (string)$ins['numero_documento'];
            if (isset($protegidos[(int)$ins['id']]) || $cuentas[$numero] !== 1 || !preg_match('/^\d{6,10}$/D',$numero)) continue;
            foreach ($documentos as $doc) {
                if (isset($docsProtegidos[(int)$doc['id']]) || (string)($doc['numero_leido']??'')===$numero) continue;
                $texto=(string)($doc['texto_crudo']??'');
                if (str_contains($texto,'[REVISION OCR]') || RecuperadorCampos::numeroFrontal($texto)!==$numero) continue;
                $leidos=RecuperadorCampos::nombres($ins['nombre'],$texto);
                if (!$leidos && !Identidad::nombresCercanos($ins['nombre'],$doc)) continue;
                $cambios=['numero_leido'=>[$doc['numero_leido']??null,$numero], 'zona_fuente'=>[$doc['zona_fuente']??null,'frontal']];
                foreach ($leidos as $campo=>$valor) if(self::vacio($doc[$campo]??null)) $cambios[$campo]=[$doc[$campo]??null,$valor];
                if(self::vacio($doc['fecha_nacimiento']??null) && ($fecha=RecuperadorCampos::fecha($texto))) {
                    $cambios['fecha_nacimiento']=[$doc['fecha_nacimiento']??null,$fecha];
                    $cambios['fecha_zona']=[$doc['fecha_zona']??null,'frontal'];
                }
                $cambio=self::cambio('documento',$doc,'documento',$doc,$cambios);
                $cambio['criterio']='número frontal y nombre respaldados por el texto OCR y la lista';
                $plan[]=$cambio;
            }
            $docs = array_values(array_filter($documentos, static fn(array $d): bool =>
                (string)($d['numero_leido'] ?? '') === $numero && !isset($docsProtegidos[(int)$d['id']])
                && (float)($d['confianza'] ?? 0) >= 55
                && !str_contains((string)($d['texto_crudo'] ?? ''),'[REVISION OCR]')));
            if (!$docs) continue;
            foreach ($docs as &$doc) {
                $recuperados = RecuperadorCampos::nombres($ins['nombre'], (string)($doc['texto_crudo'] ?? ''));
                $reparaciones = [];
                foreach (['nombres','apellidos'] as $campo) {
                    if (!self::vacio($doc[$campo] ?? null) && !empty($recuperados[$campo]) && $doc[$campo] !== $recuperados[$campo]) {
                        $reparaciones[$campo] = [$doc[$campo],$recuperados[$campo]];
                    }
                }
                if ($reparaciones) {
                    $cambio = self::cambio('documento',$doc,'documento',$doc,$reparaciones);
                    $cambio['criterio'] = 'campos de nombre respaldados por OCR y Excel';
                    $plan[] = $cambio;
                    foreach ($reparaciones as $campo=>$par) $doc[$campo] = $par[1];
                    $doc['texto_crudo'] .= "\n[DATOS COMPLETADOS]";
                }
            }
            unset($doc);
            $completos = [];
            foreach ($docs as $doc) {
                if (!self::vacio($doc['nombres'] ?? null) && !self::vacio($doc['apellidos'] ?? null)
                    && !str_contains((string)($doc['texto_crudo'] ?? ''),'[DATOS COMPLETADOS]')) {
                    $completos[Identidad::nombreDocumento($doc)] = $doc;
                }
            }
            if (count($completos) > 1) continue; // Mismo número, identidades contradictorias.
            $fuente = $completos ? reset($completos) : null;
            if (self::vacio($ins['nombre'])) {
                if ($fuente && (float)$fuente['confianza'] >= 80) {
                    $nombre = trim($fuente['nombres'].' '.$fuente['apellidos']);
                    if (mb_strlen($nombre) <= 150) $plan[] = self::cambio('inscrito',$ins,'documento',$fuente,
                        ['nombre'=>[$ins['nombre'],$nombre]]);
                }
                continue;
            }
            if ($fuente && Identidad::nombre($ins['nombre']) !== Identidad::nombreDocumento($fuente)) continue;
            foreach ($docs as $doc) {
                $cambios = [];
                $fecha = self::vacio($doc['fecha_nacimiento'] ?? null) ? RecuperadorCampos::fecha((string)($doc['texto_crudo'] ?? '')) : null;
                if ($fecha !== null) {
                    $cambios['fecha_nacimiento'] = [$doc['fecha_nacimiento'] ?? null,$fecha];
                    $cambios['fecha_zona'] = [$doc['fecha_zona'] ?? null,'frontal'];
                }
                if (!self::vacio($doc['nombres'] ?? null) && !self::vacio($doc['apellidos'] ?? null)) {
                    if ($cambios) $plan[] = self::cambio('documento',$doc,'documento',$doc,$cambios);
                    continue;
                }
                $faltanAmbos = self::vacio($doc['nombres'] ?? null) && self::vacio($doc['apellidos'] ?? null);
                if ($fuente) {
                    $compatible = true;
                    foreach (['nombres','apellidos'] as $campo) {
                        if (self::vacio($doc[$campo] ?? null)) $cambios[$campo] = [$doc[$campo] ?? null,$fuente[$campo]];
                        elseif (Identidad::nombre($doc[$campo]) !== Identidad::nombre($fuente[$campo])) $compatible = false;
                    }
                    if (!$compatible) continue;
                } elseif ($faltanAmbos) {
                    foreach (RecuperadorCampos::nombres($ins['nombre'],(string)($doc['texto_crudo'] ?? '')) as $campo=>$valor) {
                        $cambios[$campo]=[$doc[$campo]??null,$valor];
                    }
                } else {
                    $campo = self::vacio($doc['nombres'] ?? null) ? 'nombres' : 'apellidos';
                    $conocido = $doc[$campo === 'nombres' ? 'apellidos' : 'nombres'];
                    $resto = self::resto($ins['nombre'], $conocido);
                    if ($resto === null) {
                        $recuperados=RecuperadorCampos::nombres($ins['nombre'],(string)($doc['texto_crudo']??''));
                        $resto=$recuperados[$campo]??null;
                    }
                    if ($resto === null) continue;
                    $cambios[$campo] = [$doc[$campo] ?? null,$resto];
                }
                // La lista solo tiene Nombre completo: conservarlo entero
                // cuando no hay evidencia para separar nombres y apellidos.
                if (self::vacio($doc['nombre_completo'] ?? null) && mb_strlen($ins['nombre']) <= 150) {
                    $cambios['nombre_completo'] = [$doc['nombre_completo'] ?? null,$ins['nombre']];
                }
                if ($cambios) {
                    $cambio = self::cambio('documento',$doc,$fuente ? 'documento' : 'inscrito',$fuente ?: $ins,$cambios);
                    if ($fecha !== null) $cambio['evidencia_fecha_documento_id']=(int)$doc['id'];
                    if ($faltanAmbos && isset($cambios['nombres'],$cambios['apellidos']) && !$fuente) $cambio['evidencia_nombre_documento_id']=(int)$doc['id'];
                    $plan[]=$cambio;
                }
            }
        }
        return array_merge($plan, self::numerosPorNombre($inscritos, $documentos, $protegidos, $docsProtegidos));
    }

    private static function numerosPorNombre(array $inscritos, array $documentos, array $protegidos, array $docsProtegidos): array
    {
        $porNombre = []; $porNumero = []; $cambios = []; $identidadesOcr = [];
        foreach ($documentos as $d) {
            $nombreOcr = Identidad::nombreDocumento($d);
            if ($nombreOcr !== '' && !empty($d['numero_leido'])) $identidadesOcr[(string)$d['numero_leido']][$nombreOcr] = true;
        }
        foreach ($inscritos as $ins) {
            $porNombre[Identidad::nombre((string)$ins['nombre'])][] = $ins;
            $porNumero[(string)$ins['numero_documento']][] = $ins;
        }
        foreach ($porNombre as $nombre => $personas) {
            if ($nombre === '' || count($personas) !== 1) continue;
            $ins = $personas[0];
            if (self::vacio($ins['nombre']) || isset($protegidos[(int)$ins['id']])) continue;
            $lista = (string)$ins['numero_documento'];
            if (!preg_match('/^\d{6,10}$/D', $lista) || count($porNumero[$lista]) !== 1) continue;
            $docs = array_values(array_filter($documentos, static fn(array $d): bool => Identidad::nombreDocumento($d) === $nombre));
            if (!$docs) continue;
            // Todos los escaneos de ese nombre deben respaldar la misma lectura.
            $numeros = array_unique(array_map(static fn(array $d): string => (string)($d['numero_leido'] ?? ''), $docs));
            if (count($numeros) !== 1) continue;
            $leido = reset($numeros);
            if ($leido !== '' && count($identidadesOcr[$leido] ?? []) > 1) continue;
            if ($leido === $lista || ($leido !== '' && (!preg_match('/^\d{6,10}$/D',$leido)
                || levenshtein($leido,$lista) !== 1 || isset($porNumero[$leido])))) continue;
            $aptos = true;
            foreach ($docs as $d) {
                $texto = (string)($d['texto_crudo'] ?? '');
                if (isset($docsProtegidos[(int)$d['id']]) || !empty($d['error'])
                    || str_contains($texto,'[REVISION OCR]') || str_contains($texto,'[DATOS COMPLETADOS]')) $aptos = false;
            }
            if (!$aptos) continue;
            $fuente = null;
            foreach ($docs as $d) {
                $texto = (string)($d['texto_crudo'] ?? '');
                if ($leido !== '' && (float)($d['confianza'] ?? 0) >= 90
                    && in_array($d['zona_fuente'] ?? '', ['pdf417','codigo_barras'],true)
                    && (str_contains($texto,'[NUMERO VERIFICADO EN PDF417]') || str_contains($texto,'[CODIGO PDF417]'))) $fuente = $d;
            }
            if ($fuente) {
                $cambios[] = self::cambio('inscrito',$ins,'documento',$fuente,['numero_documento'=>[$lista,$leido]]);
                continue;
            }
            // Sin decodificación verificable, solo corregir una lectura frontal
            // débil (o vacía). Una puntuación alta por sí sola no decide la fuente.
            foreach ($docs as $d) {
                if (!isset($d['confianza']) || (float)$d['confianza'] >= 55
                    || in_array($d['zona_fuente'] ?? '',['pdf417','mrz','codigo_barras'],true)) { $aptos = false; break; }
            }
            if (!$aptos) continue;
            foreach ($docs as $d) $cambios[] = self::cambio('documento',$d,'inscrito',$ins,
                ['numero_leido'=>[$d['numero_leido'] ?? null,$lista]]);
        }
        return $cambios;
    }

    private static function cambio(string $destino, array $a, string $origen, array $b, array $campos): array
    {
        return ['destino'=>$destino,'destino_id'=>(int)$a['id'],'origen'=>$origen,'origen_id'=>(int)$b['id'],'campos'=>$campos];
    }

    private static function resto(string $completo, string $conocido): ?string
    {
        $tokens = preg_split('/\s+/u', trim($completo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (preg_split('/\s+/u',trim($conocido),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $indices = array_keys(array_filter($tokens, static fn(string $t): bool => Identidad::nombre($t) === Identidad::nombre($palabra)));
            if (count($indices) !== 1) return null;
            unset($tokens[$indices[0]]);
        }
        $resto = trim(implode(' ',$tokens), " ,;\t\r\n");
        return $resto !== '' && mb_strlen($resto) <= 150 ? $resto : null;
    }

    public static function completar(int $loteId): int
    {
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('SELECT estado FROM lotes_verificacion WHERE id = ? FOR UPDATE');
            $s->execute([$loteId]);
            if ($s->fetchColumn() !== 'en_proceso') { $pdo->commit(); return 0; }
            $leer = static function(string $tabla) use ($pdo,$loteId): array {
                $s=$pdo->prepare('SELECT * FROM '.$tabla.' WHERE lote_id = ? FOR UPDATE'); $s->execute([$loteId]); return $s->fetchAll();
            };
            $plan = self::plan($leer('inscritos'),$leer('documentos_ocr'),$leer('verificaciones'));
            foreach ($plan as $cambio) {
                $tabla = $cambio['destino'] === 'inscrito' ? 'inscritos' : 'documentos_ocr';
                $sets=[]; $valores=[];
                foreach ($cambio['campos'] as $campo=>$par) { $sets[]=$campo.' = ?'; $valores[]=$par[1]; }
                if ($tabla === 'documentos_ocr') {
                    $sets[]="texto_crudo = CONCAT(COALESCE(texto_crudo, ''), ?)";
                    $criterio = $cambio['criterio'] ?? (isset($cambio['campos']['numero_leido']) ? 'nombre completo único y lectura débil o vacía' : 'cédula exacta');
                    $valores[]="\n[DATOS COMPLETADOS] Campos recuperados automáticamente por " . $criterio . ' desde ' . ($cambio['origen']==='inscrito'?'la lista Excel y el texto del documento.':'el texto de un documento.');
                }
                $valores[]=$cambio['destino_id']; $valores[]=$loteId;
                $pdo->prepare('UPDATE '.$tabla.' SET '.implode(', ',$sets).' WHERE id = ? AND lote_id = ?')->execute($valores);
                $pdo->prepare('INSERT INTO autocompletados (lote_id,destino,destino_id,origen,origen_id,cambios) VALUES (?,?,?,?,?,?)')->execute([
                    $loteId,$cambio['destino'],$cambio['destino_id'],$cambio['origen'],$cambio['origen_id'],json_encode($cambio['campos'] + array_intersect_key($cambio,array_flip(['evidencia_fecha_documento_id','evidencia_nombre_documento_id'])),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            }
            $pdo->commit(); return count($plan);
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
