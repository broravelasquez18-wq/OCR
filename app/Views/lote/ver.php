<?php
/** @var array<string,mixed> $lote */
/** @var list<array<string,mixed>> $inscritos */
/** @var int $totalDocumentos */
$loteId = (int) $lote['id'];
?>
<div class="btn-row" style="justify-content:space-between">
    <h1>Lote <span class="mono"><?= htmlspecialchars((string)$lote['codigo_ficha'], ENT_QUOTES) ?></span></h1>
    <a href="<?= BASE_URL ?>/lotes">&larr; Todos los lotes</a>
</div>

<div class="card">
    <div class="stat-grid">
        <div class="stat"><div class="n"><?= count($inscritos) ?></div><div class="l">Inscritos</div></div>
        <div class="stat"><div class="n"><?= (int)$totalDocumentos ?></div><div class="l">Documentos OCR</div></div>
        <div class="stat"><div class="n"><?= htmlspecialchars((string)$lote['estado'], ENT_QUOTES) ?></div><div class="l">Estado</div></div>
    </div>
    <p class="muted" style="margin-top:1rem">
        <?= htmlspecialchars((string)($lote['programa'] ?? 'Sin programa'), ENT_QUOTES) ?>
    </p>
    <div class="btn-row" style="margin-top:1rem">
        <a class="btn" href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/documentos">Subir documentos (OCR)</a>
        <a class="btn secondary" href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/verificacion">Ver verificacion</a>
        <a class="btn secondary" href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/informe">Informe</a>
    </div>
</div>

<h2>Inscritos (<?= count($inscritos) ?>)</h2>
<div class="card table-wrap">
    <?php if (empty($inscritos)): ?>
        <p class="muted">Este lote no tiene inscritos.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Nombre</th><th>Tipo</th><th>N&deg; documento</th><th>Estado SENA</th></tr></thead>
        <tbody>
        <?php foreach ($inscritos as $i): ?>
            <tr>
                <td><?= htmlspecialchars((string)$i['nombre'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string)$i['tipo_documento'], ENT_QUOTES) ?></td>
                <td class="mono"><?= htmlspecialchars((string)$i['numero_documento'], ENT_QUOTES) ?></td>
                <td class="muted"><?= htmlspecialchars((string)($i['estado_sena'] ?? '—'), ENT_QUOTES) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
