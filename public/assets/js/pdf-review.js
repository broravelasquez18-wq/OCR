(() => {
  'use strict';
  const input = document.getElementById('pdfFile');
  const preview = document.getElementById('pdfPreview');
  const prepare = document.getElementById('pdfPrepare');
  const status = document.getElementById('pdfReviewStatus');
  const process = document.getElementById('btnPdf');
  const manual = document.getElementById('pdfManual');
  const automatic = () => !manual || !manual.checked;
  let reviewedFile = null;
  let groups = [];
  let pages = [];
  let loading = false;
  let submitted = false;
  let draggedPage = null;
  let documentPdf = null;
  const imageCache = new Map();
  const CACHE_LIMIT = 64 * 1024 * 1024;
  let cacheBytes = 0;
  function releaseDocument() {
    const previous = documentPdf;
    documentPdf = null;
    imageCache.clear(); cacheBytes = 0;
    if (previous) previous.destroy().catch(() => {});
  }
  async function renderPage(number, thumbnail = null) {
    if (imageCache.has(number)) return imageCache.get(number);
    if (!documentPdf) throw new Error('Prepara nuevamente la vista previa del PDF.');
    const page = await documentPdf.getPage(number);
    const original = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: Math.min(2, 1600 / Math.max(original.width, original.height)) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
    try {
      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
      if (thumbnail) {
        thumbnail.width = 320;
        thumbnail.height = Math.ceil(320 * canvas.height / canvas.width);
        thumbnail.getContext('2d').drawImage(canvas, 0, 0, thumbnail.width, thumbnail.height);
      }
      const blob = await new Promise((resolve, reject) => canvas.toBlob(
        (value) => value ? resolve(value) : reject(new Error('No se pudo preparar la imagen de la página.')),
        'image/jpeg', 0.92));
      if (cacheBytes + blob.size <= CACHE_LIMIT) {
        imageCache.set(number, blob); cacheBytes += blob.size;
      }
      return blob;
    } finally {
      canvas.width = canvas.height = 0;
      page.cleanup();
    }
  }
  const locked = () => loading || submitted || window.ProgresoOCR.busy;
  function clearDrag() {
    draggedPage = null;
    pages.forEach((page) => page.card.classList.remove('dragging', 'drop-target'));
  }
  function createGroup(selected) {
    if (locked()) return;
    if (selected.length < 1 || selected.length > 2 || new Set(selected).size !== selected.length) return;
    if (groups.some((group) => group.some((number) => selected.includes(number)))) { alert('Deshaz primero el grupo de esas páginas.'); return; }
    selected.sort((first, second) => first - second);
    groups.push(selected); update();
  }
  function update() {
    pages.forEach((page, index) => {
      const group = groups.find((item) => item.includes(index + 1));
      page.label.textContent = automatic() ? 'Lista para lectura automática' : group ? (group.length === 1 ? 'Completa' : 'Par: ' + group.join(' + ')) : 'Pendiente de agrupar';
      page.complete.hidden = automatic();
      page.card.draggable = !automatic() && !locked() && !group;
      page.card.classList.toggle('is-grouped', Boolean(group));
      page.complete.disabled = locked() || Boolean(group);
      page.undo.hidden = automatic() || !group;
      page.undo.disabled = locked();
    });
    const covered = groups.flat().length;
    document.getElementById('pdfCompleteRemaining').disabled = locked() || !pages.length || covered === pages.length;
    document.getElementById('pdfCompleteRemaining').hidden = automatic();
    if (manual) manual.disabled = locked();
    status.textContent = automatic() ? `${pages.length} páginas preparadas · El OCR buscará caras compatibles sin selección manual.` : `${groups.length} cédulas · ${covered} de ${pages.length} páginas revisadas`;
    process.disabled = loading || submitted || !pages.length || (!automatic() && covered !== pages.length);
  }
  if (manual) manual.addEventListener('change', update);
  input.addEventListener('change', () => {
    releaseDocument();
    clearDrag();
    reviewedFile = null; groups = []; pages = []; submitted = false;
    preview.replaceChildren(); process.disabled = true;
    document.getElementById('pdfCompleteRemaining').disabled = true;
    status.textContent = 'Prepara la vista previa antes de procesar.';
  });
  prepare.addEventListener('click', async () => {
    if (loading || window.ProgresoOCR.busy || !input.files[0]) return;
    if (submitted) { alert('Selecciona nuevamente el archivo solo si necesitas otra carga. Revisa antes los documentos guardados.'); return; }
    loading = true; prepare.disabled = input.disabled = true; process.disabled = true;
    document.getElementById('pdfCompleteRemaining').disabled = true;
    reviewedFile = null; groups = []; pages = []; preview.replaceChildren();
    releaseDocument();
    try {
      const file = input.files[0];
      // Preparar los modelos mientras el usuario revisa y agrupa las páginas.
      fetch((window.APP_BASE || '') + '/ocr/preparar', { method: 'POST' }).catch(() => {});
      if (!window.pdfjsLib) throw new Error('No se pudo cargar el lector PDF. Recarga la página.');
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = (window.APP_BASE || '') + '/assets/vendor/pdfjs/pdf.worker.min.js';
      documentPdf = await window.pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
      for (let number = 1; number <= documentPdf.numPages; number++) {
        status.textContent = `Preparando vista previa e imagen OCR: ${number} de ${documentPdf.numPages}…`;
        const canvas = document.createElement('canvas');
        await renderPage(number, canvas);
        const card = document.createElement('div'); card.className = 'pdf-page';
        card.title = 'Arrastra esta página sobre la otra cara de la misma cédula.';
        card.addEventListener('dragstart', (event) => {
          if (locked() || groups.some((group) => group.includes(number))) { event.preventDefault(); return; }
          draggedPage = number;
          event.dataTransfer.effectAllowed = 'link';
          event.dataTransfer.setData('text/plain', String(number));
          card.classList.add('dragging');
        });
        card.addEventListener('dragover', (event) => {
          if (locked() || draggedPage === null || draggedPage === number || groups.some((group) => group.includes(number))) return;
          event.preventDefault(); event.dataTransfer.dropEffect = 'link'; card.classList.add('drop-target');
        });
        card.addEventListener('dragleave', (event) => {
          if (!card.contains(event.relatedTarget)) card.classList.remove('drop-target');
        });
        card.addEventListener('drop', (event) => {
          event.preventDefault();
          const source = draggedPage;
          clearDrag();
          if (source !== null && source !== number) createGroup([source, number]);
        });
        card.addEventListener('dragend', clearDrag);
        const heading = document.createElement('strong'); heading.textContent = `Página ${number}`;
        const label = document.createElement('span');
        const complete = document.createElement('button');
        complete.type = 'button'; complete.className = 'btn secondary';
        complete.textContent = 'Marcar como completa';
        complete.setAttribute('aria-label', `Marcar página ${number} como cédula completa`);
        complete.addEventListener('click', () => createGroup([number]));
        const undo = document.createElement('button');
        undo.type = 'button'; undo.className = 'btn secondary'; undo.hidden = true;
        undo.textContent = 'Deshacer agrupación';
        undo.setAttribute('aria-label', `Deshacer agrupación de la página ${number}`);
        undo.addEventListener('click', () => {
          if (locked()) return;
          groups = groups.filter((group) => !group.includes(number));
          update();
        });
        card.append(heading, canvas, label, complete, undo); preview.append(card);
        pages.push({ label, card, complete, undo });
      }
      reviewedFile = file;
    } catch (error) {
      releaseDocument();
      pages = []; preview.replaceChildren(); alert('No se pudo preparar el PDF: ' + error.message);
    } finally {
      loading = false; prepare.disabled = input.disabled = false; update();
    }
  });
  document.getElementById('pdfCompleteRemaining').addEventListener('click', () => {
    if (locked() || !reviewedFile) return;
    const grouped = new Set(groups.flat());
    pages.forEach((page, index) => {
      if (!grouped.has(index + 1)) groups.push([index + 1]);
    });
    update();
  });
  window.PdfReview = {
    image(file, number) {
      if (file !== reviewedFile) return Promise.reject(new Error('El PDF cambió. Prepara otra vez la vista previa.'));
      return renderPage(number);
    },
    plan(file) {
      if (loading || submitted || file !== reviewedFile || !pages.length) throw new Error('Prepara el PDF antes de procesar.');
      if (!automatic() && groups.flat().length !== pages.length) throw new Error('Termina la agrupación manual antes de procesar.');
      if (automatic()) return pages.map((page, index) => [index + 1]);
      return groups.map((group) => [...group]).sort((first, second) => first[0] - second[0]);
    },
    automatic,
    started() { submitted = true; clearDrag(); input.disabled = prepare.disabled = true; update(); },
    finished() { const resultText = status.textContent; releaseDocument(); input.disabled = prepare.disabled = false; update(); if (automatic()) status.textContent = resultText; },
  };
})();
