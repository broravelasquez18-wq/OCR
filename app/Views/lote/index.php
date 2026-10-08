<?php
/** @var list<array<string,mixed>> $lotes */
function estadoBadge(string $e): string {
    return match ($e) {
        'cerrado'    => '<span class="badge ok">cerrado</span>',
        'verificado' => '<span class="badge corregido">verificado</span>',
        default      => '<span class="badge gris">en proceso</span>',
    };
}
?>
<div class="page-heading">
    <div><p class="eyebrow">CONTROL DOCUMENTAL</p><h1>Todo en su lugar.</h1><p class="muted">Gestiona tus listas, revisa documentos y completa cada verificación.</p></div>
    <a class="btn" href="<?= BASE_URL ?>/lotes/nuevo">＋ Nuevo lote</a>
</div>
<div class="dashboard-stats">
    <div class="stat"><span class="stat-icon" aria-hidden="true">▦</span><div class="l">Lotes registrados</div><div class="n"><?= count($lotes) ?></div><span class="muted">Tu historial de trabajo</span></div>
    <div class="stat"><span class="stat-icon" aria-hidden="true">◷</span><div class="l">En proceso</div><div class="n"><?= count(array_filter($lotes, static fn(array $item): bool => $item['estado'] !== 'cerrado')) ?></div><span class="muted">Pendientes de cierre</span></div>
    <div class="stat"><span class="stat-icon" aria-hidden="true">✓</span><div class="l">Filas confirmadas</div><div class="n"><?= array_sum(array_column($lotes, 'total_confirmados')) ?></div><span class="muted">Revisadas manualmente</span></div>
</div>
<div class="section-heading"><h2>Mis lotes</h2><span class="badge gris"><?= count($lotes) ?> lotes</span></div>
<div class="card table-wrap">
    <?php if (empty($lotes)): ?>
        <div class="empty-state"><div class="empty-art" aria-hidden="true"><div class="document-art"><span></span><span></span><span></span><b>✓</b></div></div><span class="eyebrow">TU PRIMER PASO</span><h2>Una lista. Todo bajo control.</h2><p>Importa el listado de inscritos del SENA para crear tu primer lote y empezar a verificar sus documentos.</p><a class="btn" href="<?= BASE_URL ?>/lotes/nuevo">＋ Importar mi primera lista</a><small>Archivo Excel .xls · Un lote por importación</small></div>
    <?php else: ?>
    <div class="filter-bar"><label for="loteSearch">Buscar lote</label><input type="search" id="loteSearch" data-table-search="tablaLotes" placeholder="Buscar por ficha o programa…"><span class="muted" data-result-count="tablaLotes" aria-live="polite"></span></div>
    <table id="tablaLotes">
        <thead>
            <tr>
                <th>Ficha</th><th>Programa</th><th>Inscritos</th>
                <th>Confirmados</th><th>Estado</th><th>Creado</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lotes as $l): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars((string)$l['codigo_ficha'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string)($l['programa'] ?? '—'), ENT_QUOTES) ?></td>
                <td><?= (int)$l['total_inscritos'] ?></td>
                <td><?= (int)$l['total_confirmados'] ?> / <?= (int)$l['total_inscritos'] ?></td>
                <td><?= estadoBadge((string)$l['estado']) ?></td>
                <td class="muted"><?= htmlspecialchars((string)$l['creado_en'], ENT_QUOTES) ?></td>
                <td class="row-acciones">
                    <a href="<?= BASE_URL ?>/lotes/<?= (int)$l['id'] ?>">Abrir →</a>
                    <form method="post" action="<?= BASE_URL ?>/lotes/<?= (int)$l['id'] ?>/eliminar"
                          data-confirm="¿Eliminar el lote &quot;<?= htmlspecialchars((string)$l['codigo_ficha'], ENT_QUOTES) ?>&quot;? Se borrarán sus inscritos, documentos e imágenes. Esta acción no se puede deshacer.">
                        <button type="submit" class="btn-link-danger" aria-label="Eliminar lote <?= htmlspecialchars((string)$l['codigo_ficha'], ENT_QUOTES) ?>">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="filter-empty muted" data-empty-for="tablaLotes" hidden>No hay lotes que coincidan con tu búsqueda.</p>
    <?php endif; ?>
</div>
<div class="guide-grid"><div><span>01</span><h3>Importa tu lista</h3><p>Crea un lote con los inscritos del reporte SENA.</p></div><div><span>02</span><h3>Digitaliza documentos</h3><p>Sube un PDF, fotografías o usa la cámara.</p></div><div><span>03</span><h3>Revisa y confirma</h3><p>Identifica diferencias y genera tu informe.</p></div></div>
