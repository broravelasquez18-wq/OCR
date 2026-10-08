<?php
/** @var array<string,mixed> $lote */
/** @var array<string,int> $resumen */
/** @var list<array<string,mixed>> $filas */
/** @var list<array<string,mixed>> $sobrantes */
$loteId = (int) $lote['id'];
$revisar = (int)$resumen['no_coincide'] + (int)$resumen['falta_documento'];

function ibadge(string $e): string {
    $txt = ['ok'=>'OK','corregido'=>'Corregido','no_coincide'=>'Revisar coincidencia','falta_documento'=>'Falta documento'][$e] ?? $e;
    return '<span class="badge ' . htmlspecialchars($e, ENT_QUOTES) . '">' . $txt . '</span>';
}

/** Formatea 'YYYY-MM-DD' a 'DD/MM/YYYY' + zona; '—' si no hay. */
function ffecha(?string $iso, ?string $zona): string {
    if ($iso === null || $iso === '' || $iso === '0000-00-00') {
        return '<i class="muted">— sin leer —</i>';
    }
    $ts = strtotime($iso);
    $txt = $ts ? date('d/m/Y', $ts) : htmlspecialchars($iso, ENT_QUOTES);
    $z = $zona ? ' <span class="muted" style="font-size:.8em">(' . htmlspecialchars($zona, ENT_QUOTES) . ')</span>' : '';
    return '<span class="mono">' . $txt . '</span>' . $z;
}

/** Fecha efectiva de una fila: la corregida a mano si existe, si no la del OCR. */
function fecha_efectiva(array $f): array {
    $manual = (int)($f['fecha_manual'] ?? 0) === 1;
    return [\App\Services\DatosPersonales::efectivos($f)['fecha'], $manual ? 'manual' : ($f['fecha_zona'] ?? null)];
}

/**
 * Diagnostico de una fila: donde esta probablemente el error (Excel u OCR),
 * mas avisos secundarios (fecha sin leer). Devuelve [requiere, motivos[], badgeHtml].
 *
 * @param list<array<string,mixed>> $sobrantes
 * @return array{0:bool,1:list<string>,2:string}
 */
function motivos_revision(array $f, array $sobrantes): array {
    $diag = \App\Services\Diagnostico::analizar($f, $sobrantes);
    $motivos = [];
    $badge = '';

    if ($diag['origen'] !== 'ninguno') {
        $badge = '<span class="badge origen-' . htmlspecialchars($diag['origen'], ENT_QUOTES) . '">'
               . htmlspecialchars($diag['etiqueta'], ENT_QUOTES) . '</span>';
        $motivos[] = htmlspecialchars($diag['detalle'], ENT_QUOTES);
    }

    $faltantes = \App\Services\DatosPersonales::faltantes($f);
    if ($faltantes) {
        $motivos[] = 'Datos faltantes: ' . htmlspecialchars(implode(', ', $faltantes), ENT_QUOTES) . '.';
    }

    return [$diag['origen'] !== 'ninguno' || $faltantes !== [], $motivos, $badge];
}

// Precalcular cuantas filas requieren accion (por numero/documento) y cuantas sin fecha.
$reqAccion = 0; $sinFecha = 0;
foreach ($filas as $f) {
    [$req, , ] = motivos_revision($f, $sobrantes);
    if ($req) { $reqAccion++; }
    [$fechaEf, ] = fecha_efectiva($f);
    if (\App\Services\Edad::calcular($fechaEf) === null) { $sinFecha++; }
}
?>
<div class="btn-row" style="justify-content:space-between">
    <h1>Informe del lote &middot; <span class="mono"><?= htmlspecialchars((string)$lote['codigo_ficha'], ENT_QUOTES) ?></span></h1>
    <div class="btn-row">
        <a href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/verificacion">Editar verificacion</a>
        <a class="btn secondary" href="#" onclick="window.print();return false">Imprimir / PDF</a>
    </div>
</div>

<div class="card">
    <p style="margin-top:0">
        <strong><?= htmlspecialchars((string)($lote['programa'] ?? 'Sin programa'), ENT_QUOTES) ?></strong><br>
        <span class="muted">Estado del lote: <?= htmlspecialchars((string)$lote['estado'], ENT_QUOTES) ?>
            &middot; Creado: <?= htmlspecialchars((string)$lote['creado_en'], ENT_QUOTES) ?></span>
    </p>
    <div class="stat-grid">
        <div class="stat"><div class="n"><?= (int)$resumen['total'] ?></div><div class="l">Inscritos</div></div>
        <div class="stat"><div class="n" style="color:#57d98a"><?= (int)$resumen['ok'] + (int)$resumen['corregido'] ?></div><div class="l">Verificados OK</div></div>
        <div class="stat"><div class="n" style="color:#ff8a8a"><?= $reqAccion ?></div><div class="l">Requieren accion</div></div>
        <div class="stat"><div class="n" style="color:#e6c34d"><?= $sinFecha ?></div><div class="l">Sin fecha nac.</div></div>
        <div class="stat"><div class="n"><?= (int)$resumen['confirmados'] ?>/<?= (int)$resumen['total'] ?></div><div class="l">Confirmados</div></div>
    </div>
</div>

<?php if ($reqAccion > 0): ?>
<div class="card" style="border-left:4px solid #ff8a8a">
    <strong style="color:#ff8a8a">⚠ <?= $reqAccion ?> fila(s) requieren revisión de documentos o datos faltantes.</strong>
    <span class="muted">Revisalas en la columna <em>¿Requiere accion?</em> y corrige en
        <a href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/verificacion">Editar verificacion</a>.</span>
</div>
<?php endif; ?>

<h2>Detalle</h2>
<div class="card table-wrap">
    <table>
        <thead>
            <tr>
                <th>Nombre en lista</th>
                <th>Nombres y apellidos revisados</th>
                <th>N&deg; en lista</th>
                <th>N&deg; confirmado</th>
                <th>Fecha nac.</th>
                <th>Edad actual</th>
                <th>Estado</th>
                <th>&iquest;Requiere accion?</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f):
            $conf = (int)$f['confirmado'] === 1;
            $numConf = $f['numero_confirmado'] ?? ($f['numero_leido'] ?? '');
            [$requiere, $motivos, $origenBadge] = motivos_revision($f, $sobrantes);
            $confianzaTxt = $f['confianza'] !== null ? rtrim(rtrim(number_format((float)$f['confianza'],2),'0'),'.') . '%' : '—';
        ?>
            <tr class="row-<?= htmlspecialchars((string)$f['estado'], ENT_QUOTES) ?>"<?= $requiere ? ' style="background:rgba(255,138,138,.08)"' : '' ?>>
                <td><?= htmlspecialchars((string)$f['nombre'], ENT_QUOTES) ?></td>
                <td><?php $datos = \App\Services\DatosPersonales::efectivos($f); ?><?= htmlspecialchars($datos['nombres'] ?: 'Faltan nombres', ENT_QUOTES) ?><br><?= htmlspecialchars($datos['apellidos'] ?: 'Faltan apellidos', ENT_QUOTES) ?></td>
                <td class="mono"><?= htmlspecialchars((string)$f['numero_documento'], ENT_QUOTES) ?></td>
                <td class="mono"><?= $numConf !== '' && $numConf !== null ? htmlspecialchars((string)$numConf, ENT_QUOTES) : '<i class="muted">—</i>' ?></td>
                <td><?php [$fEf, $fZona] = fecha_efectiva($f); echo ffecha($fEf, $fZona); ?></td>
                <td><?php $edad = \App\Services\Edad::calcular($fEf); echo $edad !== null ? $edad . ' años' : 'Sin calcular'; ?></td>
                <td><?= ibadge((string)$f['estado']) ?><?= $conf ? ' <span class="badge ok">✓</span>' : '' ?></td>
                <td style="font-size:.85em">
                    <?php if ($motivos === []): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <?php if ($origenBadge !== ''): ?><div style="margin-bottom:.3rem"><?= $origenBadge ?></div><?php endif; ?>
                        <ul style="margin:0;padding-left:1.1em">
                            <?php foreach ($motivos as $m): ?>
                                <li<?= $requiere ? ' style="color:#ff8a8a"' : ' class="muted"' ?>><?= $m ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($sobrantes)): ?>
<h2>Documentos sin inscrito (<?= count($sobrantes) ?>)</h2>
<div class="card table-wrap">
    <table>
        <thead><tr><th>N&deg; leido</th><th>Fecha nac. (OCR)</th><th>Zona</th><th>Origen</th></tr></thead>
        <tbody>
        <?php foreach ($sobrantes as $s): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars((string)$s['numero_leido'], ENT_QUOTES) ?></td>
                <td><?= ffecha($s['fecha_nacimiento'] ?? null, $s['fecha_zona'] ?? null) ?></td>
                <td class="muted"><?= htmlspecialchars((string)($s['zona_fuente'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="muted"><?= htmlspecialchars((string)$s['origen'], ENT_QUOTES) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<p class="muted" style="font-size:.82rem">
    La fecha de nacimiento se lee del MRZ (cedulas nuevas) o del texto frontal; cuando el
    escaneo es de baja calidad puede quedar sin leer y debe verificarse a mano.
</p>
