(() => {
  'use strict';
  const form = document.querySelector('form[data-carga-conjunta]');
  if (!form) return;
  const excel = document.getElementById('lista');
  const pdfs = document.getElementById('pdfFiles');
  const status = document.getElementById('cargaEstado');
  const results = document.getElementById('btnIrVerificacion');
  const resume = document.getElementById('continuarDocumentos');
  const submit = form.querySelector('button[type="submit"]');
  let consumed = false;
  const recovery = 'Comprueba Mis lotes antes de volver a importar: el lote podría haberse creado.';

  function selection() {
    status.textContent = excel.files.length && pdfs.files.length
      ? `${excel.files[0].name} · ${pdfs.files.length} PDF seleccionados. Listos para comparar.`
      : 'Selecciona el Excel y al menos un PDF para comenzar.';
  }
  excel.addEventListener('change', selection);
  pdfs.addEventListener('change', selection);

  function importar() {
    return new Promise((resolve, reject) => {
      const request = new XMLHttpRequest();
      request.open('POST', form.action);
      request.setRequestHeader('Accept', 'application/json');
      request.timeout = 300000;
      request.upload.onprogress = (event) => {
        if (event.lengthComputable) window.ProgresoOCR.phase('Subiendo Excel…', `${Math.round(event.loaded / event.total * 100)}% enviado`);
      };
      request.upload.onload = () => window.ProgresoOCR.phase('Importando inscritos…', 'Validando la ficha y guardando la lista.');
      request.onload = () => {
        let data;
        try { data = JSON.parse(request.responseText); }
        catch (_) { reject(new Error('Respuesta no confirmada. ' + recovery)); return; }
        if (request.status >= 200 && request.status < 300 && data?.ok === true && Number.isInteger(data.lote_id) && data.lote_id > 0) {
          resolve(data.lote_id);
        } else {
          // Solo un rechazo explícito de validación permite volver a importar.
          if (request.status === 422 && data?.ok === false && !data.resultado_desconocido) consumed = false;
          reject(new Error((data?.error || 'No se pudo confirmar la importación.') + (consumed ? ' ' + recovery : '')));
        }
      };
      request.onerror = () => reject(new Error('Se perdió la conexión. ' + recovery));
      request.ontimeout = () => reject(new Error('Se agotó el tiempo de espera. ' + recovery));
      const body = new FormData();
      body.append('lista', excel.files[0]);
      request.send(body);
    });
  }

  async function image(pdf, number) {
    const page = await pdf.getPage(number);
    const original = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: Math.min(2, 1600 / Math.max(original.width, original.height)) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
    try {
      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
      return await new Promise((resolve, reject) => canvas.toBlob(
        blob => blob ? resolve(blob) : reject(new Error(`No se pudo leer la página ${number}.`)), 'image/jpeg', 0.92));
    } finally {
      canvas.width = canvas.height = 0;
      page.cleanup();
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (consumed || !form.reportValidity() || !window.ProgresoOCR.begin('Procesando Excel y PDF…')) return;
    const files = Array.from(pdfs.files);
    const documents = [];
    let loteId = null;
    submit.disabled = excel.disabled = pdfs.disabled = true;
    try {
      if (!excel.files[0] || !/\.xls$/i.test(excel.files[0].name) || !files.length || files.some(file => !/\.pdf$/i.test(file.name))) {
        throw new Error('Selecciona un Excel .xls y al menos un archivo PDF.');
      }
      if (!window.pdfjsLib) throw new Error('No se pudo cargar el lector PDF. Recarga la página.');
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = (window.APP_BASE || '') + '/assets/vendor/pdfjs/pdf.worker.min.js';
      // Comprobar todos los PDF antes de crear el lote.
      for (const file of files) {
        window.ProgresoOCR.phase('Comprobando los PDF…', file.name);
        const task = window.pdfjsLib.getDocument({ data: await file.arrayBuffer() });
        let pdf;
        try {
          pdf = await new Promise((resolve, reject) => {
            task.onPassword = () => reject(new Error('PDF protegido'));
            task.promise.then(resolve, reject);
          });
        } catch (_) {
          await task.destroy();
          throw new Error(`No se pudo abrir ${file.name}. Usa un PDF válido y sin contraseña.`);
        }
        documents.push({ file, pdf });
        if (!pdf.numPages || pdf.numPages > 1000) throw new Error(`${file.name}: selecciona un PDF de 1 a 1000 páginas.`);
      }
      consumed = true;
      window.ProgresoOCR.phase('Importando Excel…', excel.files[0].name);
      loteId = await importar();
      window.OcrCarga.lote(loteId);
      results.href = (window.APP_BASE || '') + `/lotes/${loteId}/verificacion`;
      resume.href = (window.APP_BASE || '') + `/lotes/${loteId}/documentos`;
      results.hidden = resume.hidden = false;
      window.ProgresoOCR.total(documents.reduce((sum, item) => sum + item.pdf.numPages, 0));
      for (const { file, pdf } of documents) {
        window.ProgresoOCR.phase('Leyendo las cédulas…', file.name);
        const groups = Array.from({ length: pdf.numPages }, (_, index) => [index + 1]);
        await window.OcrCarga.procesarPdf(file, groups, number => image(pdf, number));
      }
      await window.OcrCarga.comparar();
      status.textContent = 'Proceso finalizado. Abre la comparación para revisar los resultados y los posibles avisos.';
      window.ProgresoOCR.finish();
    } catch (error) {
      const message = error.message + (loteId ? ' El lote ya está creado. Revisa los resultados y continúa desde la carga de documentos.' : '');
      status.textContent = message;
      window.ProgresoOCR.finish(message);
    } finally {
      await Promise.allSettled(documents.map(({ pdf }) => pdf.destroy()));
      submit.disabled = excel.disabled = pdfs.disabled = consumed;
    }
  });
})();
