<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;

/** Agrupa únicamente lecturas compatibles de la misma carga; conserva los originales. */
final class AgrupadorPdf
{
    private static function letras(string $valor): string
    {
        return preg_replace('/\s+/u', '', mb_strtoupper(trim($valor))) ?? '';
    }

    public static function soloFormato(array $cambios): bool
    {
        if (!$cambios) return false;
        foreach ($cambios as $campo=>$par) {
            if (!in_array($campo,['nombres','apellidos'],true) || !is_array($par) || count($par)!==2
                || !is_string($par[0]) || !is_string($par[1]) || self::letras($par[0])==='' || self::letras($par[0])!==self::letras($par[1])) return false;
        }
        return true;
    }
    /** Propuesta de revisión, nunca coincidencia ni permiso para copiar campos. */
    public static function propuesta(array $a, array $b): bool
    {
        if (!empty($a['ruta_reverso']) || !empty($b['ruta_reverso'])) return false;
        if (($a['zona_fuente'] ?? '') === 'codigo_barras') [$a, $b] = [$b, $a];
        if (($a['zona_fuente'] ?? '') !== 'frontal' || ($b['zona_fuente'] ?? '') !== 'codigo_barras') return false;
        if (empty($a['nombres']) || empty($a['apellidos']) || !empty($b['nombres']) || !empty($b['apellidos'])) return false;
        if ((int)($a['pagina'] ?? 0) < 1 || (int)($b['pagina'] ?? 0) !== (int)$a['pagina'] + 1) return false;
        foreach ([$a,$b] as $d) {
            if ((float)($d['confianza'] ?? 0) < 55 || preg_match('/\[(?:REVISION OCR|DATOS COMPLETADOS)\]/', $d['texto_crudo'] ?? '')) return false;
        }
        $na = (string)($a['numero_leido'] ?? ''); $nb = (string)($b['numero_leido'] ?? '');
        if (!ctype_digit($na) || !ctype_digit($nb) || strlen($na) < 6 || strlen($na) !== strlen($nb)) return false;
        $diferencias = 0;
        for ($i=0; $i<strlen($na); $i++) if ($na[$i] !== $nb[$i]) $diferencias++;
        // Exigir evidencia del serial en el propio reverso, no un número
        // inferido del Excel ni una MRZ de otra persona.
        return $diferencias === 1 && preg_match('/-[MF]-0*'.preg_quote($nb,'/').'(?=[-\s]|$)/', $b['texto_crudo'] ?? '') === 1;
    }

    private static function nombresMrzCoinciden(array $frente, array $reverso): bool
    {
        if (($reverso['zona_fuente'] ?? '') !== 'mrz' || empty($frente['nombres']) || empty($frente['apellidos'])) return false;
        $patron = static function (string $nombre): string {
            $nombre = strtr(mb_strtoupper(trim($nombre)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
            $tokens = preg_split('/[\s\-]+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY);
            return implode('[<K]+', array_map(static fn($t)=>preg_quote($t, '/'), $tokens));
        };
        // K puede ser una lectura del relleno <. Solo aceptar separadores
        // entre palabras completas respaldadas por el frente; no cambiar
        // letras dentro de los nombres (KARLA sigue siendo KARLA).
        $regex = '/^' . $patron($frente['apellidos']) . '<{2}' . $patron($frente['nombres']) . '[<K]*$/';
        foreach (preg_split('/\R/u', (string)($reverso['texto_crudo'] ?? '')) as $linea) {
            if (preg_match($regex, trim($linea))) return true;
        }
        return false;
    }

    public static function compatibles(array $a, array $b): bool
    {
        if (!empty($a['ruta_reverso']) || !empty($b['ruta_reverso'])) return false;
        foreach ([$a, $b] as $d) {
            if ((float)($d['confianza'] ?? 0) < 55 || str_contains($d['texto_crudo'] ?? '', '[REVISION OCR]')) return false;
        }
        $na = preg_replace('/\D/', '', $a['numero_leido'] ?? '');
        $nb = preg_replace('/\D/', '', $b['numero_leido'] ?? '');
        if ($na !== '' && $nb !== '' && $na !== $nb) return false;
        $sameNumber = strlen($na) >= 6 && $na === $nb;
        if (!$sameNumber) return false;
        $mrzNombres = self::nombresMrzCoinciden($a, $b) || self::nombresMrzCoinciden($b, $a);
        foreach (['nombres','apellidos'] as $field) {
            if (!empty($a[$field]) && !empty($b[$field]) && self::letras($a[$field]) !== self::letras($b[$field]) && !$mrzNombres) return false;
        }
        if (!empty($a['fecha_nacimiento']) && !empty($b['fecha_nacimiento']) && $a['fecha_nacimiento'] !== $b['fecha_nacimiento']) return false;
        // Dos páginas completas o duplicadas no necesitan convertirse en un par.
        $complement = false;
        foreach (['numero_leido','nombres','apellidos','fecha_nacimiento'] as $field) {
            if (empty($a[$field]) !== empty($b[$field])) $complement = true;
        }
        $carasDistintas = (($a['zona_fuente'] ?? '') === 'mrz') !== (($b['zona_fuente'] ?? '') === 'mrz');
        $nombresCompletos = !empty($a['nombres']) && !empty($a['apellidos']) && !empty($b['nombres']) && !empty($b['apellidos']);
        return $complement || ($carasDistintas && ($mrzNombres || $nombresCompletos));
    }

    public static function agrupar(int $lote, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id)=>$id > 0)));
        if (count($ids) < 2) return 0;
        if (count($ids) > 1000) throw new \RuntimeException('Demasiadas páginas.');
        $db = Database::conn();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT estado FROM lotes_verificacion WHERE id=? FOR UPDATE');
            $lock->execute([$lote]);
            if ($lock->fetchColumn() === 'cerrado') throw new \RuntimeException('El lote está cerrado.');
            $q = $db->prepare('SELECT * FROM documentos_ocr WHERE lote_id=? AND origen="pdf" AND id IN (' . implode(',', array_fill(0,count($ids),'?')) . ') ORDER BY pagina,id FOR UPDATE');
            $q->execute([$lote,...$ids]);
            $docs = $q->fetchAll();
            $conAutocompletado = [];
            foreach ($docs as $d) if (str_contains($d['texto_crudo'] ?? '', '[DATOS COMPLETADOS]')) $conAutocompletado[$d['id']] = true;
            $protected = $db->prepare('SELECT * FROM verificaciones WHERE lote_id=? AND documento_ocr_id IS NOT NULL FOR UPDATE');
            $protected->execute([$lote]);
            $used = []; $assigned = [];
            foreach ($protected->fetchAll() as $v) {
                $assigned[$v['documento_ocr_id']][] = (int)$v['inscrito_id'];
                if ($v['confirmado'] || $v['corregido_manual'] || $v['numero_confirmado'] !== null
                    || $v['nombres_manual'] !== null || $v['apellidos_manual'] !== null || $v['fecha_manual']) $used[$v['documento_ocr_id']] = true;
            }
            $audit = $db->prepare('SELECT destino_id,cambios FROM autocompletados WHERE lote_id=? AND destino="documento"');
            $audit->execute([$lote]);
            foreach ($audit->fetchAll() as $cambio) {
                if (isset($conAutocompletado[$cambio['destino_id']]) && !self::soloFormato(json_decode($cambio['cambios'],true) ?? [])) $used[$cambio['destino_id']] = true;
            }
            $paths = $db->prepare('SELECT ruta_reverso FROM documentos_ocr WHERE lote_id=? AND ruta_reverso IS NOT NULL');
            $paths->execute([$lote]);
            $pairedPaths = $paths->fetchAll(\PDO::FETCH_COLUMN);
            $docs = array_values(array_filter($docs, fn($d)=>!isset($used[$d['id']]) && !in_array($d['ruta_imagen'],$pairedPaths,true)));
            $candidates = [];
            foreach ($docs as $i=>$a) foreach ($docs as $j=>$b) {
                $personas = array_unique(array_merge($assigned[$a['id']] ?? [], $assigned[$b['id']] ?? []));
                if (count($personas) > 1) continue;
                if ($i !== $j && (self::compatibles($a,$b) || self::propuesta($a,$b))) $candidates[$i][] = $j;
            }
            $count = 0;
            foreach ($docs as $i=>$a) {
                if (count($candidates[$i] ?? []) !== 1) continue;
                $j = $candidates[$i][0];
                if ($j <= $i || count($candidates[$j] ?? []) !== 1) continue;
                $b = $docs[$j];
                $propuesta = !self::compatibles($a,$b);
                // Mantener los nombres impresos del frente cuando el reverso
                // MRZ usa transliteración o confunde rellenos con K.
                if (($a['zona_fuente'] ?? '') === 'mrz' && ($b['zona_fuente'] ?? '') !== 'mrz') {
                    [$a, $b] = [$b, $a];
                }
                if ($propuesta && ($a['zona_fuente'] ?? '') === 'codigo_barras') [$a,$b] = [$b,$a];
                foreach ($propuesta ? [] : ['numero_leido','nombres','apellidos','fecha_nacimiento'] as $field) {
                    if (empty($a[$field])) { $a[$field] = $b[$field]; if ($field === 'fecha_nacimiento') $a['fecha_zona'] = $b['fecha_zona']; }
                }
                $marca = $propuesta ? "\n[REVISION OCR]\n[PAR PROPUESTO OCR]\nLas páginas consecutivas difieren en un dígito. Se conservan los campos del frente; verifica el reverso antes de confirmar.\n[SEGUNDA PAGINA OCR]\n" : "\n[SEGUNDA PAGINA OCR]\n";
                $update = $db->prepare('UPDATE documentos_ocr SET ruta_reverso=?,pagina_reverso=?,numero_leido=?,nombres=?,apellidos=?,fecha_nacimiento=?,fecha_zona=?,confianza=?,texto_crudo=? WHERE id=?');
                $update->execute([$b['ruta_imagen'],$b['pagina'],$a['numero_leido'],$a['nombres'],$a['apellidos'],$a['fecha_nacimiento'],$a['fecha_zona'],min($a['confianza'],$b['confianza']),$a['texto_crudo'].$marca.$b['texto_crudo'],$a['id']]);
                $refs = $db->prepare('UPDATE verificaciones SET documento_ocr_id=?, estado=CASE WHEN ?=1 THEN "no_coincide" ELSE estado END WHERE lote_id=? AND documento_ocr_id IN (?,?) AND confirmado=0 AND corregido_manual=0 AND numero_confirmado IS NULL AND nombres_manual IS NULL AND apellidos_manual IS NULL AND fecha_manual=0');
                $refs->execute([$a['id'],(int)$propuesta,$lote,$a['id'],$b['id']]);
                $count++;
            }
            $db->commit();
            return $count;
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}
