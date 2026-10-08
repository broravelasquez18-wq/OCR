<?php
/** @var array<string,mixed> $lote */
/** @var int $totalInscritos */
/** @var int $totalDocumentos */
$loteId = (int) $lote['id'];
?>
<div class="btn-row" style="justify-content:space-between">
    <h1>Subir documentos &middot; <span class="mono"><?= htmlspecialchars((string)$lote['codigo_ficha'], ENT_QUOTES) ?></span></h1>
    <a href="<?= BASE_URL ?>/lotes/<?= $loteId ?>">&larr; Volver al lote</a>
</div>

<div class="card">
    <h2>Elige cómo cargar tus documentos</h2>
    <p class="muted" style="margin-top:0">
        <?= (int)$totalInscritos ?> inscritos en la lista &middot;
        <span id="docCount"><?= (int)$totalDocumentos ?></span> documentos procesados.
        Extraemos identificación, nombres, apellidos y fecha de nacimiento. La edad se calcula a partir de la fecha.
        En PDF, el OCR busca automáticamente páginas de la misma persona para unir sus datos. También puedes seleccionar dos fotos de una cédula.
    </p>

    <div class="uploader-tabs">
        <button type="button" class="tab-btn active" data-pane="pane-pdf">📄 PDF multipagina</button>
        <button type="button" class="tab-btn" data-pane="pane-fotos">🖼️ Fotos sueltas</button>
        <button type="button" class="tab-btn" data-pane="pane-camara">📷 Camara</button>
    </div>

    <!-- PANE: PDF -->
    <div id="pane-pdf" class="pane active">
        <label for="pdfFile">Archivo PDF</label>
        <input type="file" id="pdfFile" accept="application/pdf">
        <p class="review-note">Prepara el PDF y pulsa Procesar PDF. No necesitas escoger los pares: el OCR une lecturas compatibles de la misma persona. Las páginas dudosas quedan disponibles para revisión.</p>
        <div class="btn-row">
            <button type="button" class="btn secondary" id="pdfPrepare">Preparar PDF</button>
        </div>
        <p id="pdfReviewStatus" role="status">Selecciona el archivo y pulsa Preparar PDF.</p>
        <details><summary>Ver páginas y opciones de agrupación manual</summary>
        <label><input type="checkbox" id="pdfManual"> Agrupar caras manualmente (opcional)</label>
        <p class="muted">En modo manual, arrastra una página sobre su otra cara o márcala como completa.</p>
        <div id="pdfPreview" class="pdf-preview"></div></details>
        <div class="btn-row" style="margin-top:.75rem">
            <button type="button" class="btn secondary" id="pdfCompleteRemaining" disabled hidden>Marcar restantes como completas</button>
            <button class="btn" id="btnPdf" disabled>Procesar PDF</button>
        </div>
    </div>

    <!-- PANE: FOTOS -->
    <div id="pane-fotos" class="pane">
        <h3>Una cédula en dos fotos</h3>
        <label for="fotoFrente">Frente de la cédula</label>
        <input type="file" id="fotoFrente" accept="image/jpeg,image/png,image/webp,image/bmp">
        <label for="fotoReverso">Reverso de la misma cédula</label>
        <input type="file" id="fotoReverso" accept="image/jpeg,image/png,image/webp,image/bmp">
        <div class="btn-row"><button class="btn" id="btnDosCaras" type="button">Procesar ambas caras juntas</button></div>
        <h3>Documentos independientes</h3>
        <label for="fotoFiles">Imagenes de cedulas (puedes seleccionar varias)</label>
        <input type="file" id="fotoFiles" accept="image/*" multiple>
        <div class="btn-row" style="margin-top:.75rem">
            <button class="btn" id="btnFotos" disabled>Procesar fotos</button>
        </div>
    </div>

    <!-- PANE: CAMARA -->
    <div id="pane-camara" class="pane">
        <div class="btn-row" style="margin-bottom:.6rem">
            <button class="btn secondary" id="btnCamStart">Encender camara</button>
            <button class="btn" id="btnCapturar" disabled>Capturar y procesar</button>
            <button class="btn secondary" id="btnCamStop" disabled>Apagar</button>
        </div>
        <video id="video" autoplay playsinline muted style="display:none"></video>
        <canvas id="canvasCam" class="preview" style="display:none"></canvas>
    </div>

    <div class="progress" id="progressWrap" style="display:none"><div id="progressBar"></div></div>
</div>

<div class="btn-row">
    <a class="btn ok" href="<?= BASE_URL ?>/lotes/<?= $loteId ?>/verificacion" id="btnIrVerificacion">Ir a verificacion →</a>
</div>

<canvas id="canvasPdf" style="display:none"></canvas>

<script>
  window.LOTE_ID = <?= $loteId ?>;
</script>
<!-- Lector y worker locales: la carga de PDF no depende del CDN. -->
<script src="<?= BASE_URL ?>/assets/vendor/pdfjs/pdf.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/pdf-review.js?v=13"></script>
<script src="<?= BASE_URL ?>/assets/js/uploader.js?v=19"></script>
