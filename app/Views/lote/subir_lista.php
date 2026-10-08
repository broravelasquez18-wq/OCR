<?php /** @var string|null $error */ ?>
<div class="page-heading"><div><p class="eyebrow">NUEVO LOTE</p><h1>Excel y PDF, en un solo proceso.</h1><p class="muted">Selecciona la lista y las cédulas. Importamos los inscritos, leemos los documentos y comparamos sus datos automáticamente.</p></div></div>

<?php if (!empty($error)): ?>
    <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
<?php endif; ?>

<div class="import-grid"><div class="card">
    <h2>Archivos para comparar</h2>
    <form method="post" action="<?= BASE_URL ?>/lotes/importar" enctype="multipart/form-data" data-carga-conjunta>
        <label for="lista">Lista de inscritos · Excel (.xls)</label>
        <input type="file" id="lista" name="lista" accept=".xls" required>
        <p class="muted">Usa el reporte original del SENA con el código de ficha y los encabezados de identificación, nombre y estado.</p>
        <label for="pdfFiles">Cédulas · Archivos PDF</label>
        <input type="file" id="pdfFiles" accept=".pdf,application/pdf" multiple required>
        <p class="muted">Puedes seleccionar varios PDF de la misma ficha. Las caras compatibles se unen automáticamente dentro de cada archivo.</p>
        <p id="cargaEstado" role="status" aria-live="polite">Selecciona ambos tipos de archivo para comenzar.</p>
        <div style="margin-top:1rem">
            <button class="btn" type="submit">Procesar y comparar →</button>
            <a class="btn secondary" href="<?= BASE_URL ?>/lotes">Cancelar</a>
        </div>
    </form>
    <noscript><p class="alert error">Activa JavaScript para procesar el Excel y los PDF juntos.</p></noscript>
    <div class="btn-row" style="margin-top:1rem">
        <a class="btn ok" id="btnIrVerificacion" hidden>Ver comparación →</a>
        <a class="btn secondary" id="continuarDocumentos" hidden>Continuar carga de documentos</a>
    </div>
</div>
<aside class="card help-card"><span class="eyebrow">UN SOLO INICIO</span><h2>De los archivos a la comparación</h2><ol><li>Selecciona el Excel y todos los PDF de la ficha.</li><li>Pulsa <strong>Procesar y comparar</strong> y mantén esta página abierta.</li><li>Revisa las coincidencias, diferencias y datos faltantes antes de confirmar.</li></ol><p class="muted">Solo se permite un lote por ficha. Si ya existe, abre su lote desde Mis lotes para agregar documentos.</p><p class="muted">Si la lectura se interrumpe, el lote y los documentos guardados quedan disponibles para continuar.</p></aside></div>
<script src="<?= BASE_URL ?>/assets/vendor/pdfjs/pdf.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/uploader.js?v=19"></script>
<script src="<?= BASE_URL ?>/assets/js/carga-conjunta.js?v=3" defer></script>
