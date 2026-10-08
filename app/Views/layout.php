<?php
/** @var string $content */
/** @var string $titulo */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (BASE_URL !== '' && str_starts_with((string) $currentPath, BASE_URL)) {
    $currentPath = substr((string) $currentPath, strlen(BASE_URL)) ?: '/';
}
$isImport = $currentPath === '/lotes/nuevo' || $currentPath === '/lotes/importar';
$currentStep = str_ends_with($currentPath, '/documentos') ? 2 : (str_ends_with($currentPath, '/verificacion') ? 3 : (str_ends_with($currentPath, '/informe') ? 4 : 1));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titulo ?? 'Verificador OCR', ENT_QUOTES) ?></title>
    <script>window.APP_BASE = <?= json_encode(BASE_URL) ?>;</script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css?v=14">
    <script src="<?= BASE_URL ?>/assets/js/progreso.js?v=8" defer></script>
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<aside class="sidebar">
    <a class="brand" href="<?= BASE_URL ?>/lotes"><span class="brand-mark" aria-hidden="true">◎</span><span>Verifica<span class="brand-accent">OCR</span><small>GESTIÓN DOCUMENTAL</small></span></a>
    <span class="nav-label">ESPACIO DE TRABAJO</span>
    <nav aria-label="Navegación principal">
        <a href="<?= BASE_URL ?>/lotes" class="<?= !$isImport ? 'active' : '' ?>" <?= !$isImport ? 'aria-current="page"' : '' ?>><span aria-hidden="true">▦</span> Mis lotes</a>
        <a href="<?= BASE_URL ?>/lotes/nuevo" class="<?= $isImport ? 'active' : '' ?>" <?= $isImport ? 'aria-current="page"' : '' ?>><span aria-hidden="true">＋</span> Importar y comparar</a>
    </nav>
    <div class="sidebar-note"><span class="note-symbol" aria-hidden="true">◎</span><strong>La revisión importa</strong><p>El OCR te ayuda a leer. Tú confirmas que la información sea correcta.</p></div>
    <div class="sidebar-bottom">VerificaOCR <span class="version">V2.0</span></div>
</aside>
<div class="workspace">
<header class="topbar"><span>Workspace <span class="breadcrumb-separator">/</span> <?= isset($lote) ? 'Detalle del lote' : ($isImport ? 'Nueva importación' : 'Vista general') ?></span><span class="workspace-tag">Acceso directo</span></header>
<main class="container" id="contenido">
    <?php if (isset($lote)): ?>
    <nav class="workflow" aria-label="Pasos del lote">
        <?php foreach ([1 => ['Lista', ''], 2 => ['Documentos', '/documentos'], 3 => ['Verificación', '/verificacion'], 4 => ['Informe', '/informe']] as $step => $stepData): ?>
        <a class="<?= $step === $currentStep ? 'current' : '' ?>" href="<?= BASE_URL ?>/lotes/<?= (int)$lote['id'] ?><?= $stepData[1] ?>" <?= $step === $currentStep ? 'aria-current="step"' : '' ?>><span><?= $step ?></span><?= $stepData[0] ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?= $content ?>
</main>
<footer class="foot">
    <small>VerificaOCR · Documentos organizados, decisiones claras.</small><small>Versión 2.0</small>
</footer>
</div>
<dialog id="progresoModal" class="progress-modal" aria-labelledby="progresoTitulo" aria-describedby="progresoDetalle">
    <header class="progress-modal-header"><span class="progress-modal-icon" aria-hidden="true">◔</span><div><h2 id="progresoTitulo">Procesando documentos…</h2><p id="progresoSubtitulo">Preparando archivos</p></div></header>
    <div class="progress-modal-body">
        <div id="progresoAnillo" class="progress-ring" role="progressbar" aria-label="Progreso del proceso" aria-valuemin="0" aria-valuemax="100">
            <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="ring-track" cx="60" cy="60" r="49"/><circle class="ring-value" cx="60" cy="60" r="49" pathLength="100"/></svg>
            <strong id="progresoPorcentaje">…</strong>
        </div>
        <p id="progresoDetalle" role="status" aria-live="polite">Espera un momento.</p>
        <div id="progresoResumen" class="progress-summary" hidden>
            <div class="progress-stat saved"><strong id="progresoGuardados">0</strong><span>Guardados</span><small>Confirmados por el servidor</small></div>
            <div class="progress-stat review"><strong id="progresoRevision">0</strong><span>Por revisar</span><small>Dentro de los guardados</small></div>
            <div class="progress-stat pending"><strong id="progresoPendientes">0</strong><span>Sin confirmar</span><small>Guardado no confirmado</small></div>
            <div class="progress-stat failed"><strong id="progresoErrores">0</strong><span>Errores</span><small>Operaciones fallidas</small></div>
        </div>
        <details id="progresoAviso" class="progress-notice" hidden>
            <summary>Ver detalle del último aviso</summary>
            <p id="progresoAvisoTexto"></p>
        </details>
        <small id="progresoConteo">No cierres esta página durante el proceso.</small>
        <button type="button" class="btn" id="progresoCerrar" hidden>Ver resultados</button>
    </div>
</dialog>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=5" defer></script>
</body>
</html>
