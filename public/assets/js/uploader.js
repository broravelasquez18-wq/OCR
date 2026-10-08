/* =====================================================================
 * Verificador OCR - adaptadores de entrada (PDF / fotos / camara).
 * Los tres terminan enviando una imagen (Blob) a POST /ocr/procesar.
 * ===================================================================== */
(function () {
  'use strict';

  let LOTE_ID = window.LOTE_ID;
  const docCountEl = document.getElementById('docCount');
  const progressWrap = document.getElementById('progressWrap');
  const progressBar = document.getElementById('progressBar');
  // Cuantas imagenes se procesan en paralelo (aprovecha varios nucleos del
  // servidor). El servidor limita los hilos de OCR por proceso (OCR_THREADS).
  // Un pedido por cada motor persistente: evitar espera en cola dentro de PHP.
  const OCR_CONCURRENCIA = Math.max(1, window.OCR_CONCURRENCIA || 2);

  let procesados = parseInt(docCountEl ? docCountEl.textContent : '0', 10) || 0;
  let pdfDocumentIds = null;

  async function compararCarga() {
    window.ProgresoOCR.phase('Comprobando OCR con el Excel…', 'Detectando diferencias y corrigiendo campos respaldados por ambos documentos.');
    const response = await fetch((window.APP_BASE || '') + '/ocr/finalizar', {
      method: 'POST', body: new URLSearchParams({ lote_id: LOTE_ID }), signal: AbortSignal.timeout(30000),
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error('La carga se guardó, pero la comparación no terminó. Abre Verificación para completarla; no necesitas volver a subir el archivo.');
  }

  function subir(form, filename) {
    return new Promise((resolve, reject) => {
      const started = performance.now();
      let uploadedMs = null;
      const request = new XMLHttpRequest();
      request.open('POST', (window.APP_BASE || '') + '/ocr/procesar');
      request.timeout = 30000;
      request.upload.onprogress = (event) => {
        const avance = event.lengthComputable ? ' · envío ' + Math.round(event.loaded / event.total * 100) + '%' : '';
        window.ProgresoOCR.phase('Subiendo documento…', filename + avance);
      };
      request.upload.onload = () => {
        uploadedMs = Math.round(performance.now() - started);
        window.ProgresoOCR.phase('Leyendo y guardando datos…', filename + ' · El porcentaje avanza al terminar cada documento.');
      };
      request.onload = () => {
        try {
          const data = JSON.parse(request.responseText);
          // Diagnóstico sin nombres, identificaciones ni contenido del documento.
          window.OCR_TIMINGS = window.OCR_TIMINGS || [];
          window.OCR_TIMINGS.push({ total_ms: Math.round(performance.now() - started),
            envio_ms: uploadedMs, servidor: data.tiempos_ms || null });
          resolve({ ok: request.status >= 200 && request.status < 300, status: request.status, data });
        } catch (error) {
          reject(new Error('El servidor no devolvió una respuesta válida. Comprueba los documentos guardados antes de reintentar.'));
        }
      };
      request.onerror = () => reject(new Error('Se perdió la conexión. Comprueba si se guardó el documento antes de reintentar.'));
      request.ontimeout = () => reject(new Error('Tiempo de espera agotado. Comprueba si se guardó el documento antes de reintentar.'));
      request.send(form);
    });
  }

  // ---------------------------------------------------------------------
  // Utilidades UI
  // ---------------------------------------------------------------------
  function log(msg, cls) {
    if (cls !== 'err') return;
    if (window.ProgresoOCR.busy) window.ProgresoOCR.error(msg);
    else alert(msg);
  }

  function setProgress(done, total) {
    if (!progressWrap || !progressBar) return;
    if (total <= 0) { progressWrap.style.display = 'none'; return; }
    progressWrap.style.display = 'block';
    progressBar.style.width = Math.round((done / total) * 100) + '%';
    if (done >= total) setTimeout(() => (progressWrap.style.display = 'none'), 800);
  }

  function bumpCount() {
    procesados += 1;
    if (docCountEl) docCountEl.textContent = String(procesados);
  }

  // ---------------------------------------------------------------------
  // Envio comun al endpoint OCR
  // ---------------------------------------------------------------------
  async function enviar(blob, filename, origen, pagina, reverso = null, paginaReverso = null) {
    const fd = new FormData();
    fd.append('imagen', blob, filename);
    fd.append('lote_id', LOTE_ID);
    fd.append('origen', origen);
    if (pagina != null) fd.append('pagina', pagina);
    if (reverso) {
      fd.append('imagen_reverso', reverso, 'reverso.jpg');
      if (paginaReverso != null) fd.append('pagina_reverso', paginaReverso);
    }

    try {
      window.ProgresoOCR.phase('Subiendo documento…', filename);
      const resp = await subir(fd, filename);
      const data = resp.data;
      if (!data || typeof data !== 'object' || typeof data.ok !== 'boolean') {
        throw new Error('Respuesta incompleta. Comprueba los documentos guardados antes de reintentar.');
      }
      if (!resp.ok || !data.ok) {
        window.ProgresoOCR.error(filename + ': ' + (data.error || ('HTTP ' + resp.status)));
        return data.resultado_desconocido || resp.status >= 500 || data.ok ? 'unknown' : 'error';
      }
      if (!Number.isInteger(data.documento_id) || data.documento_id <= 0) {
        throw new Error('No se recibió la confirmación del documento guardado. Revisa Verificación antes de reintentar.');
      }
      bumpCount();
      if (origen === 'pdf' && pdfDocumentIds) pdfDocumentIds.push(data.documento_id);
      const completo = data.numero && data.nombres && data.apellidos && data.fecha_nacimiento && data.edad != null;
      if (data.ocr_error) window.ProgresoOCR.error(filename + ': ' + data.ocr_error);
      return completo && !data.ocr_error ? 'ok' : 'review';
    } catch (e) {
      window.ProgresoOCR.error(filename + ': ' + e.message);
      return 'unknown';
    }
  }

  function canvasToBlob(canvas, quality) {
    return new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('No se pudo convertir la página en imagen.')), 'image/jpeg', quality || 0.92));
  }

  // Ejecuta `tareas` (funciones que devuelven Promise) con concurrencia limitada.
  // Llama `alTerminarCada(resultado, hechos, total)` conforme cada una termina.
  async function procesarEnPool(tareas, limite, alTerminarCada) {
    let indice = 0;
    let hechos = 0;
    const total = tareas.length;
    async function worker() {
      while (indice < tareas.length) {
        const actual = tareas[indice++];
        let resultado;
        try {
          resultado = await actual();
        } catch (error) {
          resultado = 'unknown';
          window.ProgresoOCR.error(error.message || 'Error procesando un documento.');
        }
        hechos += 1;
        alTerminarCada(resultado, hechos, total);
      }
    }
    const workers = [];
    for (let i = 0; i < Math.min(limite, tareas.length); i++) workers.push(worker());
    await Promise.all(workers);
  }

  // Motor compartido por la carga conjunta y la carga adicional del lote.
  async function procesarPdf(file, groups, image, automatic = true) {
    pdfDocumentIds = [];
    try {
      const tareas = groups.map((group) => async () => {
        const [pagina, paginaReverso] = group;
        const frente = await image(pagina);
        const reverso = paginaReverso ? await image(paginaReverso) : null;
        return enviar(frente, file.name.replace(/\.pdf$/i, '') + '_p' + group.join('-') + '.jpg', 'pdf', pagina, reverso, paginaReverso ?? null);
      });
      await procesarEnPool(tareas.slice(0, 1), 1, (result) => {
        window.ProgresoOCR.step(result);
        setProgress(1, tareas.length);
      });
      await procesarEnPool(tareas.slice(1), OCR_CONCURRENCIA, (result, done) => {
        window.ProgresoOCR.step(result);
        setProgress(done + 1, tareas.length);
      });
      if (automatic && pdfDocumentIds.length > 1) {
        window.ProgresoOCR.phase('Uniendo caras compatibles…', file.name);
        const response = await fetch((window.APP_BASE || '') + '/ocr/agrupar-pdf', {
          method: 'POST', body: new URLSearchParams({ lote_id: LOTE_ID, documentos: JSON.stringify(pdfDocumentIds) }),
          signal: AbortSignal.timeout(30000),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error('Las páginas se guardaron, pero no se pudo completar la unión automática. Revisa los documentos sin volver a cargarlos.');
        procesados = result.documentos;
        if (docCountEl) docCountEl.textContent = String(procesados);
        const status = document.getElementById('pdfReviewStatus');
        if (status) status.textContent = `${result.pares} pares unidos automáticamente. Las páginas sin coincidencia segura se conservan para revisión.`;
      }
    } finally {
      pdfDocumentIds = null;
    }
  }
  window.OcrCarga = {
    lote(id) { LOTE_ID = id; },
    procesarPdf,
    comparar: compararCarga,
  };
  if (document.querySelector('form[data-carga-conjunta]')) return;

  // ---------------------------------------------------------------------
  // Tabs
  // ---------------------------------------------------------------------
  document.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
      document.querySelectorAll('.pane').forEach((p) => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(btn.dataset.pane).classList.add('active');
    });
  });

  // ---------------------------------------------------------------------
  // 1) PDF multipagina
  // ---------------------------------------------------------------------
  const pdfFile = document.getElementById('pdfFile');
  const btnPdf = document.getElementById('btnPdf');

  btnPdf.addEventListener('click', async () => {
    const file = pdfFile.files[0];
    if (!file) return;
    let groups;
    try { groups = window.PdfReview.plan(file); }
    catch (error) { alert(error.message); return; }
    if (!window.ProgresoOCR.begin('Procesando PDF…', groups.length)) return;
    window.PdfReview.started();
    btnPdf.disabled = true;
    try {
      window.ProgresoOCR.phase('Enviando las imágenes preparadas…', 'La primera lectura aparecerá cuando termine el primer documento.');
      await procesarPdf(file, groups, (number) => window.PdfReview.image(file, number), window.PdfReview.automatic?.());
      await compararCarga();
      window.ProgresoOCR.finish();
    } catch (e) {
      log('✖ Error leyendo PDF: ' + e.message, 'err');
      window.ProgresoOCR.finish(e.message);
    } finally {
      pdfDocumentIds = null;
      window.PdfReview.finished();
    }
  });

  // ---------------------------------------------------------------------
  // 2) Fotos sueltas
  // ---------------------------------------------------------------------
  const fotoFiles = document.getElementById('fotoFiles');
  const btnFotos = document.getElementById('btnFotos');
  fotoFiles.addEventListener('change', () => { btnFotos.disabled = !fotoFiles.files.length; });

  btnFotos.addEventListener('click', async () => {
    const files = Array.from(fotoFiles.files);
    if (!files.length) return;
    if (!window.ProgresoOCR.begin('Procesando fotografías…', files.length)) return;
    btnFotos.disabled = true;
    try {
      const tareas = files.map((file) => () => enviar(file, file.name, 'foto', null));
      await procesarEnPool(tareas, OCR_CONCURRENCIA, (result, done, total) => {
        window.ProgresoOCR.step(result);
        setProgress(done, total);
      });
      await compararCarga();
      window.ProgresoOCR.finish();
    } catch (error) {
      window.ProgresoOCR.finish(error.message);
    } finally {
      btnFotos.disabled = false;
    }
  });

  // ---------------------------------------------------------------------
  // 3) Camara en vivo
  // ---------------------------------------------------------------------
  const video = document.getElementById('video');
  document.getElementById('btnDosCaras').addEventListener('click', async () => {
    const frente = document.getElementById('fotoFrente').files[0];
    const reverso = document.getElementById('fotoReverso').files[0];
    if (!frente || !reverso) {
      log('Selecciona el frente y el reverso de la misma persona.', 'err');
      return;
    }
    const button = document.getElementById('btnDosCaras');
    if (!window.ProgresoOCR.begin('Procesando ambas caras…', 1)) return;
    button.disabled = true;
    try {
      window.ProgresoOCR.step(await enviar(frente, frente.name + ' + ' + reverso.name, 'foto', null, reverso));
      await compararCarga();
      window.ProgresoOCR.finish();
    } catch (error) {
      window.ProgresoOCR.finish(error.message);
    } finally {
      button.disabled = false;
    }
  });
  const canvasCam = document.getElementById('canvasCam');
  const btnCamStart = document.getElementById('btnCamStart');
  const btnCapturar = document.getElementById('btnCapturar');
  const btnCamStop = document.getElementById('btnCamStop');
  let stream = null;
  let capCount = 0;

  btnCamStart.addEventListener('click', async () => {
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1920 } },
        audio: false,
      });
      video.srcObject = stream;
      video.style.display = 'block';
      btnCapturar.disabled = false;
      btnCamStop.disabled = false;
      btnCamStart.disabled = true;
    } catch (e) {
      log('✖ No se pudo acceder a la camara: ' + e.message, 'err');
    }
  });

  btnCapturar.addEventListener('click', async () => {
    if (!stream) return;
    if (!window.ProgresoOCR.begin('Procesando captura…', 1)) return;
    btnCapturar.disabled = true;
    try {
      canvasCam.width = video.videoWidth;
      canvasCam.height = video.videoHeight;
      canvasCam.getContext('2d').drawImage(video, 0, 0);
      canvasCam.style.display = 'block';
      const blob = await canvasToBlob(canvasCam, 0.92);
      window.ProgresoOCR.step(await enviar(blob, 'camara_' + (++capCount) + '.jpg', 'camara', null));
      await compararCarga();
      window.ProgresoOCR.finish();
    } catch (error) {
      window.ProgresoOCR.finish(error.message);
    } finally {
      btnCapturar.disabled = !stream;
    }
  });

  function stopCam() {
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.style.display = 'none';
    btnCapturar.disabled = true;
    btnCamStop.disabled = true;
    btnCamStart.disabled = false;
  }
  btnCamStop.addEventListener('click', stopCam);
  window.addEventListener('beforeunload', stopCam);
})();
