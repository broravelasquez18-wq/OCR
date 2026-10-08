<?php
/** @var array<string,mixed> $lote */
/** @var list<array<string,mixed>> $filas */
/** @var list<array<string,mixed>> $sobrantes */
/** @var array<string,int> $resumen */
/** @var bool $todasConfirmadas */
$loteId = (int) $lote['id'];
function badge(string $e): string {
    $txt = ['ok'=>'OK','corregido'=>'Corregido','no_coincide'=>'Revisar coincidencia','falta_documento'=>'Falta documento'][$e] ?? $e;
    return '<span class="badge ' . htmlspecialchars($e, ENT_QUOTES) . '">' . $txt . '</span>';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/verificacion.css?v=4">
<div class="btn-row" style="justify-content:space-between">
    <h1>Verificacion &middot; <span class="mono"><?= htmlspecialchars((string)$lote['codigo_ficha'], ENT_QUOTES) ?></span></h1>
    <a href="<?= BASE_URL ?>/lotes/<?= $loteId ?>">&larr; Volver al lote</a>
</div>

<div class="card">
    <div class="stat-grid">
        <div class="stat"><div class="n"><?= (int)$resumen['total'] ?></div><div class="l">Inscritos</div></div>
        <div class="stat"><div class="n" style="color:#57d98a"><?= (int)$resumen['ok'] ?></div><div class="l">OK</div></div>
        <div class="stat"><div class="n" style="color:#e6c34d"><?= (int)$resumen['corregido'] ?></div><div class="l">Corregidos</div></div>
        <div class="stat"><div class="n" style="color:#ff8a8a"><?= (int)$resumen['no_coincide'] ?></div><div class="l">Revisar coincidencia</div></div>
        <div class="stat"><div class="n" style="color:#ff8a8a"><?= (int)$resumen['falta_documento'] ?></div><div class="l">Falta doc.</div></div>
        <div class="stat"><div class="n"><?= (int)$resumen['confirmados'] ?>/<?= (int)$resumen['total'] ?></div><div class="l">Confirmados</div></div>
    </div>
    <div class="btn-row" style="margin-top:1rem">
        <form method="post" action="<?= BASE_URL ?>/lotes/<?= $loteId ?>/comparar" style="display:inline">
            <button class="btn secondary" type="submit">↻ Recalcular cruce</button>
        </form>
        <button class="btn ok" id="btnCerrar" data-lote="<?= $loteId ?>" <?= $todasConfirmadas ? '' : 'disabled' ?>>
            Cerrar lote
        </button>
        <a class="btn secondary" href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/informe">Ver informe</a>
        <span class="muted" id="cerrarHint" style="<?= $todasConfirmadas ? 'display:none' : '' ?>">
            (se habilita cuando todas las filas esten confirmadas)
        </span>
    </div>
</div>

<div class="card table-wrap verification-table-wrap">
    <p class="review-note">El cruce automático marca OK cuando coinciden el número y los nombres, sin alertas de lectura. Las coincidencias aproximadas quedan para revisión. La fecha de nacimiento debe comprobarse en la imagen antes de confirmar.</p>
    <div class="filter-bar"><div><label for="verSearch">Buscar inscrito</label><input type="search" id="verSearch" data-table-search="tablaVer" placeholder="Nombre o número de documento…"></div><div><label for="verStatus">Mostrar</label><select id="verStatus" data-table-status="tablaVer"><option value="">Todos los estados</option value="falta_documento">Falta documento</option><option value="no_coincide">Revisar coincidencia</option><option value="ok">OK</option><option value="corregido">Corregido</option><option value="pendiente">Sin confirmar</option></select></div><span class="muted" data-result-count="tablaVer" aria-live="polite"></span></div>
    <details class="verification-help"><summary>Cómo revisar y guardar los datos</summary><p class="review-note">Compara los campos con la imagen y pulsa Guardar para conservar tus cambios. Pulsa Confirmar cuando termines de revisar a la persona. La edad se calcula automáticamente. Para modificar una ficha confirmada, pulsa Editar; quedará pendiente de confirmar y, si el lote estaba cerrado, se reabrirá.</p></details>
    <label><input type="checkbox" id="soloDatosFaltantes"> Mostrar solo filas con datos faltantes</label>
    <table id="tablaVer">
        <thead>
            <tr>
                <th>Inscrito e identificación</th>
                <th>Nombres y apellidos</th>
                <th>Nacimiento y edad</th>
                <th>Estado y pendientes</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f):
            $estado = (string) $f['estado'];
            $confirmado = (int) $f['confirmado'] === 1;
            $numLista = (string) $f['numero_documento'];
            $numLeido = $f['numero_leido'] !== null ? (string) $f['numero_leido'] : '';
            $valorEdit = $f['numero_confirmado'] ?? ($numLeido !== '' ? $numLeido : '');
            // Fecha a editar: la corregida a mano si existe, si no la leida por OCR.
            $fechaOcr = ($f['fecha_nacimiento'] ?? null) ?: null;
            $fechaConf = ($f['fecha_confirmada'] ?? null) ?: null;
            $datos = \App\Services\DatosPersonales::efectivos($f);
            $conciliacion = $f['conciliacion'] ?? ['valores'=>[], 'detalles'=>[]];
            foreach (['nombres', 'apellidos'] as $campo) {
                if (isset($conciliacion['valores'][$campo])) $datos[$campo] = $conciliacion['valores'][$campo];
            }
            $valorEdit = $conciliacion['valores']['numero'] ?? $valorEdit;
            $faltantes = \App\Services\DatosPersonales::faltantes($f);
            $fechaEdit = $datos['fecha'] ?? '';
            $fechaManual = (int) ($f['fecha_manual'] ?? 0) === 1;
            $diag = \App\Services\Diagnostico::analizar($f, $sobrantes);
        ?>
            <tr class="row-<?= htmlspecialchars($estado, ENT_QUOTES) ?>"
                data-ver="<?= (int)$f['id'] ?>"
                data-numlista="<?= htmlspecialchars($numLista, ENT_QUOTES) ?>"
                data-incomplete="<?= $faltantes ? '1' : '0' ?>"
                data-confirmado="<?= $confirmado ? '1' : '0' ?>">
                <td data-label="Inscrito e identificación">
                    <div class="verification-presence" role="status"><?php if ($f['documento_ocr_id'] !== null): ?>
                        <span class="badge ok">En Excel y <?= ($f['origen'] ?? '') === 'pdf' ? 'PDF' : 'imagen' ?></span>
                        <small>Documento asociado<?= $estado === 'no_coincide' ? ' · coincidencia por revisar' : '' ?></small>
                    <?php else: ?>
                        <span class="badge falta_documento">En Excel · PDF no localizado</span>
                        <small>No se ha asociado un documento a esta persona.</small>
                    <?php endif; ?>
                    <?php if (!$confirmado && empty($f['corregido_manual']) && $f['nombres_manual'] === null && $f['apellidos_manual'] === null && $f['documento_ocr_id'] !== null
                        && \App\Services\Identidad::nombreDocumento(['nombres'=>$f['nombres_ocr'],'apellidos'=>$f['apellidos_ocr']]) !== \App\Services\Identidad::nombre($f['nombre'])): ?>
                        <small class="name-comparison-warning">⚠ Nombres o apellidos diferentes del Excel. Revisa la comparación.</small>
                    <?php elseif (!$confirmado && str_contains((string)($f['texto_crudo'] ?? ''),'[DATOS COMPLETADOS]')): ?>
                        <small>Datos completados por comparación automática · pendientes de confirmar.</small>
                    <?php endif; ?></div>
                    <strong class="verification-name"><?= htmlspecialchars((string)$f['nombre'], ENT_QUOTES) ?></strong>
                    <?php if (!empty($f['ruta_imagen'])): ?>
                        <div class="verification-images">
                        <?php foreach (['principal' => 'Imagen asociada', 'reverso' => 'Segunda cara'] as $cara => $etiqueta): ?>
                            <?php if ($cara === 'reverso' && empty($f['ruta_reverso'])) continue; ?>
                            <a href="<?= BASE_URL ?>/verificaciones/<?= (int)$f['id'] ?>/imagen/<?= $cara ?>" target="_blank" rel="noopener" title="Ampliar <?= $etiqueta ?>">
                                <img loading="lazy" src="<?= BASE_URL ?>/verificaciones/<?= (int)$f['id'] ?>/imagen/<?= $cara ?>" alt="<?= $etiqueta ?> de <?= htmlspecialchars((string)$f['nombre'], ENT_QUOTES) ?>">
                                <span><?= $etiqueta ?> · Ampliar</span>
                            </a>
                        <?php endforeach; ?>
                        </div>
                        <small class="muted">Comprueba que corresponde a esta persona. Una imagen puede contener ambas caras.</small>
                    <?php else: ?>
                        <p class="muted">Sin imagen asociada. <a href="#imagenes-sin-asociar">Revisa las imágenes guardadas sin asociar</a> antes de volver a cargar el documento.</p>
                    <?php endif; ?>
                    <div class="verification-number"><span class="muted">En lista:</span> <span class="mono"><?= htmlspecialchars($numLista, ENT_QUOTES) ?></span></div>
                    <div class="verification-number"><span class="muted">OCR:</span> <span class="mono col-leido"><?= $numLeido !== '' ? htmlspecialchars($numLeido, ENT_QUOTES) : 'Sin leer' ?></span></div>
                    <label for="numero-<?= (int)$f['id'] ?>">Identificación editable</label>
                    <input id="numero-<?= (int)$f['id'] ?>" type="text" class="num-input mono input-num" inputmode="numeric"
                           value="<?= htmlspecialchars((string)$valorEdit, ENT_QUOTES) ?>"
                           <?= $confirmado ? 'disabled' : '' ?>>
                </td>
                <td data-label="Nombres y apellidos">
                    <?php if (!empty($f['nombre_completo']) && (empty($datos['nombres']) || empty($datos['apellidos']))): ?>
                        <p class="review-note">Nombre completo recuperado: <strong><?= htmlspecialchars((string)$f['nombre_completo'], ENT_QUOTES) ?></strong><?php if (empty($datos['nombres']) || empty($datos['apellidos'])): ?>. Pendiente separar nombres y apellidos.<?php endif; ?></p>
                    <?php endif; ?>
                    <label for="nombres-<?= (int)$f['id'] ?>">Nombres</label>
                    <input id="nombres-<?= (int)$f['id'] ?>" type="text" class="input-nombres" maxlength="150" placeholder="Faltan nombres" value="<?= htmlspecialchars($datos['nombres'], ENT_QUOTES) ?>" <?= $confirmado ? 'disabled' : '' ?>>
                    <label for="apellidos-<?= (int)$f['id'] ?>">Apellidos</label>
                    <input id="apellidos-<?= (int)$f['id'] ?>" type="text" class="input-apellidos" maxlength="150" placeholder="Faltan apellidos" value="<?= htmlspecialchars($datos['apellidos'], ENT_QUOTES) ?>" <?= $confirmado ? 'disabled' : '' ?>>
                    <details class="verification-source"><summary>Ver datos de origen</summary><small class="muted"><?= str_contains((string)($f['texto_crudo'] ?? ''), '[DATOS COMPLETADOS]') ? 'Datos OCR y completados automáticamente' : 'OCR original' ?>: <?= htmlspecialchars(trim(($f['nombres_ocr'] ?? '') . ' ' . ($f['apellidos_ocr'] ?? '')) ?: 'Sin leer', ENT_QUOTES) ?></small>
                    <?php if (!empty($f['nombre_completo'])): ?><small class="muted">Nombre completo recuperado: <?= htmlspecialchars((string)$f['nombre_completo'], ENT_QUOTES) ?></small><?php endif; ?></details>
                </td>
                <td data-label="Nacimiento y edad">
                    <label for="fecha-<?= (int)$f['id'] ?>">Fecha de nacimiento</label>
                    <input id="fecha-<?= (int)$f['id'] ?>" type="date" class="fecha-input mono input-fecha"
                           max="<?= (new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota')))->format('Y-m-d') ?>"
                           value="<?= htmlspecialchars((string)$fechaEdit, ENT_QUOTES) ?>"
                           <?= $confirmado ? 'disabled' : '' ?>>
                    <div class="muted" style="font-size:.75em;margin-top:.15rem">
                        <?php if (!empty($f['ruta_reverso'])): ?><div>Frente + reverso<?= $f['pagina'] && $f['pagina_reverso'] ? ' · páginas ' . (int)$f['pagina'] . '–' . (int)$f['pagina_reverso'] : '' ?></div><?php endif; ?>
                        <?php if ($fechaManual): ?>
                            corregida a mano
                        <?php elseif ($fechaOcr !== null): ?>
                            OCR<?= $f['fecha_zona'] ? ' (' . htmlspecialchars((string)$f['fecha_zona'], ENT_QUOTES) . ')' : '' ?>
                        <?php else: ?>
                            sin leer — escríbela
                        <?php endif; ?>
                    </div>
                    <div class="verification-age"><span class="muted">Edad</span><strong class="col-edad"><?php $edad = \App\Services\Edad::calcular($fechaEdit); echo $edad !== null ? $edad . ' años' : 'Sin calcular'; ?></strong></div>
                </td>
                <td data-label="Estado y pendientes">
                    <?php foreach ($conciliacion['detalles'] as $detalle): ?>
                        <p class="review-note"><strong>Comparación Excel ↔ PDF:</strong> <?= htmlspecialchars($detalle, ENT_QUOTES) ?></p>
                    <?php endforeach; ?>
                    <div class="col-estado">
                    <?= badge($estado) ?><?= $confirmado ? ' <span class="badge ok">✓</span>' : '' ?>
                    <?php if (!$confirmado && $diag['origen'] !== 'ninguno'): ?>
                        <div style="margin-top:.35rem">
                            <span class="badge origen-<?= htmlspecialchars($diag['origen'], ENT_QUOTES) ?>"
                                  title="<?= htmlspecialchars($diag['detalle'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($diag['etiqueta'], ENT_QUOTES) ?>
                            </span>
                            <details class="verification-diagnostic"><summary>Detalle del diagnóstico</summary><div class="diag-detalle"><?= htmlspecialchars($diag['detalle'], ENT_QUOTES) ?></div></details>
                        </div>
                    <?php endif; ?>
                    </div>
                    <div class="col-faltantes"><?= $faltantes ? 'Falta: ' . htmlspecialchars(implode(', ', $faltantes), ENT_QUOTES) : 'Campos llenos · comprueba su contenido' ?></div>
                    <details class="verification-notes" <?= !empty($f['observacion']) ? 'open' : '' ?>><summary><?= !empty($f['observacion']) ? 'Observaciones guardadas' : 'Añadir observación' ?></summary>
                    <label for="observacion-<?= (int)$f['id'] ?>">Observaciones de la revisión</label>
                    <textarea id="observacion-<?= (int)$f['id'] ?>" class="input-observacion" rows="3" placeholder="Ej.: falta reverso, falta frente, no corresponde o ambas caras verificadas" <?= $confirmado ? 'disabled' : '' ?>><?= htmlspecialchars((string)($f['observacion'] ?? ''), ENT_QUOTES) ?></textarea>
                    <small class="muted">Se guarda junto con los datos de esta persona.</small></details>
                </td>
                <td class="btn-row col-accion" data-label="Acciones">
                    <?php if ($confirmado): ?>
                        <span class="muted">Confirmado</span>
                        <button class="btn secondary btn-editar" type="button">Editar</button>
                    <?php else: ?>
                        <button class="btn secondary btn-guardar" type="button">Guardar</button>
                        <button class="btn btn-confirmar" type="button">Confirmar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="filter-empty muted" data-empty-for="tablaVer" hidden>No hay inscritos que coincidan con estos filtros.</p>
    <?php if (empty($filas)): ?>
        <p class="muted">No hay filas de verificacion. ¿Ya importaste inscritos y procesaste documentos?</p>
    <?php endif; ?>
</div>

<?php if (!empty($sobrantes)): ?>
<h2 id="imagenes-sin-asociar">Imágenes sin asociar (<?= count($sobrantes) ?>)</h2>
<div class="card table-wrap">
    <p class="muted" style="margin-top:0">Estas imágenes sí se guardaron, pero no están asociadas a una persona. Amplía la imagen, comprueba de quién es y selecciona el inscrito. No se cambian tus datos manuales.</p>
    <table>
        <thead><tr><th>Imagen guardada</th><th>N&deg; leído</th><th>Asociar a una persona</th></tr></thead>
        <tbody>
        <?php foreach ($sobrantes as $s): ?>
            <tr>
                <td>
                    <?php foreach (['principal' => 'ruta_imagen', 'reverso' => 'ruta_reverso'] as $cara => $ruta): if (empty($s[$ruta])) continue; ?>
                        <a href="<?= BASE_URL ?>/documentos/<?= (int)$s['id'] ?>/imagen/<?= $cara ?>" target="_blank" rel="noopener">
                            <img loading="lazy" src="<?= BASE_URL ?>/documentos/<?= (int)$s['id'] ?>/imagen/<?= $cara ?>" alt="Imagen <?= $cara ?> del documento <?= (int)$s['id'] ?>" style="width:150px;max-width:100%;height:140px;object-fit:contain">
                        </a>
                    <?php endforeach; ?>
                    <div class="muted">Página <?= (int)$s['pagina'] ?><?= !empty($s['pagina_reverso']) ? ' y ' . (int)$s['pagina_reverso'] : '' ?> · Pulsa para ampliar</div>
                </td>
                <td class="mono"><?= htmlspecialchars((string)($s['numero_leido'] ?: 'Sin lectura'), ENT_QUOTES) ?></td>
                <td>
                    <div class="document-presence"><span class="badge no_coincide">En <?= ($s['origen'] ?? '') === 'pdf' ? 'PDF' : 'imagen' ?> · sin asociar al Excel</span><small class="muted">Puede estar en la lista y requerir asociación manual.</small></div>
                    <form class="form-asociar-imagen" data-origen="<?= htmlspecialchars((string)($s['origen'] ?? ''), ENT_QUOTES) ?>" method="post" action="<?= BASE_URL ?>/documentos/<?= (int)$s['id'] ?>/asociar">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['asociar_imagen_token'], ENT_QUOTES) ?>">
                        <label for="asociar-<?= (int)$s['id'] ?>">¿A quién pertenece esta cédula?</label>
                        <select id="asociar-<?= (int)$s['id'] ?>" name="verificacion_id" required style="width:100%;max-width:360px">
                            <option value="">Selecciona una persona</option>
                            <?php foreach ($filas as $destino): if ($destino['documento_ocr_id'] !== null || $destino['confirmado']) continue; ?>
                                <option value="<?= (int)$destino['id'] ?>"><?= htmlspecialchars($destino['nombre'] . ' · ' . $destino['numero_documento'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn">Asociar imagen</button>
                        <p class="association-status" role="status" aria-live="polite"></p>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/verificacion.js?v=12"></script>
