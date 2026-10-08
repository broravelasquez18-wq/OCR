(() => {
  'use strict';
  const dialog = document.getElementById('progresoModal');
  if (!dialog) return;
  const title = document.getElementById('progresoTitulo');
  const subtitle = document.getElementById('progresoSubtitulo');
  const detail = document.getElementById('progresoDetalle');
  const count = document.getElementById('progresoConteo');
  const ring = document.getElementById('progresoAnillo');
  const percent = document.getElementById('progresoPorcentaje');
  const close = document.getElementById('progresoCerrar');
  const resultsLink = document.getElementById('btnIrVerificacion');
  const summary = document.getElementById('progresoResumen');
  const notice = document.getElementById('progresoAviso');
  const noticeText = document.getElementById('progresoAvisoTexto');
  let busy = false;
  let startedAt = 0;
  let timer = null;
  const elapsed = () => {
    const seconds = Math.floor((performance.now() - startedAt) / 1000);
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
  };
  let total = null;
  let done = 0;
  let errors = 0;
  let reviews = 0;
  let unknown = 0;
  let lastError = '';
  let previousFocus = null;
  function draw() {
    const value = total ? Math.round(done / total * 100) : null;
    ring.classList.toggle('indeterminate', value === null);
    if (value === null) ring.removeAttribute('aria-valuenow');
    else ring.setAttribute('aria-valuenow', String(value));
    ring.style.setProperty('--progress', String(value ?? 25));
    percent.textContent = value === null ? '…' : value + '%';
    count.textContent = total ? `${done} de ${total} documentos atendidos · ${elapsed()} transcurridos · ${errors} errores · ${reviews} por revisar · ${unknown} sin respuesta confirmada` : `Espera mientras termina el proceso · ${elapsed()} transcurridos`;
  }
  window.ProgresoOCR = {
    get busy() { return busy; },
    begin(label, amount = null) {
      if (busy) return false;
      previousFocus = document.activeElement;
      busy = true;
      startedAt = performance.now();
      clearInterval(timer);
      timer = setInterval(draw, 1000);
      total = amount;
      done = errors = reviews = unknown = 0;
      lastError = '';
      title.textContent = label;
      subtitle.textContent = 'Preparando archivos…';
      detail.textContent = 'No cierres esta página durante el proceso.';
      close.hidden = true;
      summary.hidden = true;
      notice.hidden = true;
      notice.open = false;
      noticeText.textContent = '';
      dialog.classList.remove('is-finished');
      dialog.classList.remove('has-errors');
      draw();
      if (!dialog.open) dialog.showModal();
      return true;
    },
    total(amount) { total = amount; draw(); },
    error(message) { lastError = message; },
    phase(label, message) { subtitle.textContent = label; detail.textContent = message; },
    step(result) { done += 1; if (result === 'error') errors += 1; if (result === 'review') reviews += 1; if (result === 'unknown') unknown += 1; draw(); },
    finish(error = null) {
      busy = false;
      clearInterval(timer);
      title.textContent = error ? 'Proceso interrumpido' : (unknown || errors || reviews ? 'Carga finalizada con avisos' : 'Carga completada');
      subtitle.textContent = error ? 'Conservamos el avance del proceso' : 'Tu resumen de procesamiento';
      detail.textContent = unknown ? 'Algunos documentos podrían haberse guardado. Revisa Verificación antes de repetir la carga.' : (error ? 'Revisa el detalle del aviso antes de continuar.' : (reviews || errors ? 'Consulta los documentos que necesitan atención en Verificación.' : 'Tus documentos están listos para verificar.'));
      document.getElementById('progresoGuardados').textContent = String(done - errors - unknown);
      document.getElementById('progresoRevision').textContent = String(reviews);
      document.getElementById('progresoPendientes').textContent = String(unknown);
      document.getElementById('progresoErrores').textContent = String(errors);
      summary.hidden = !total && done === 0;
      noticeText.textContent = error || lastError;
      notice.hidden = !noticeText.textContent;
      count.textContent = total ? `${done} de ${total} documentos atendidos · ${elapsed()} transcurridos` : 'El proceso ha finalizado.';
      dialog.classList.add('is-finished');
      dialog.classList.toggle('has-errors', Boolean(error || errors || unknown));
      ring.classList.remove('indeterminate');
      if (!total) percent.textContent = error ? '!' : '✓';
      close.hidden = false;
      close.textContent = resultsLink && !resultsLink.hidden ? 'Ver resultados' : 'Cerrar y revisar';
      close.focus();
    },
  };
  dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
  close.addEventListener('click', () => {
    if (busy) return;
    if (resultsLink && !resultsLink.hidden) {
      location.assign(resultsLink.href);
      return;
    }
    dialog.close();
  });
  dialog.addEventListener('close', () => { if (previousFocus && previousFocus.isConnected) previousFocus.focus(); });
  window.addEventListener('beforeunload', (event) => {
    if (busy) { event.preventDefault(); event.returnValue = ''; }
  });
  document.querySelectorAll('form[data-progress-import]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.reportValidity() || !window.ProgresoOCR.begin('Importando lista…')) return;
      window.ProgresoOCR.phase('Cargando y creando el lote', 'Guardando los inscritos. Este paso puede tardar unos momentos.');
      const request = new XMLHttpRequest();
      request.open('POST', form.action);
      request.timeout = 300000;
      request.upload.onprogress = (progress) => {
        if (progress.lengthComputable) window.ProgresoOCR.phase('Subiendo archivo…', `Archivo enviado: ${Math.round(progress.loaded / progress.total * 100)}%. Después se importará la lista.`);
      };
      request.upload.onload = () => window.ProgresoOCR.phase('Importando inscritos…', 'El archivo se recibió. Validando la lista y guardando el lote.');
      request.onload = () => {
        const url = new URL(request.responseURL || form.action);
        const base = (window.APP_BASE || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const rutaLoteOk = new RegExp('^' + base + '/lotes/\\d+$');
        if (request.status >= 200 && request.status < 300 && url.origin === location.origin && rutaLoteOk.test(url.pathname)) {
          busy = false;
          clearInterval(timer);
          title.textContent = 'Lista importada';
          detail.textContent = 'Abriendo el lote…';
          location.assign(url.href);
          return;
        }
        const page = new DOMParser().parseFromString(request.responseText, 'text/html');
        const message = page.querySelector('.alert.error');
        window.ProgresoOCR.finish(message ? message.textContent.trim() : 'No se pudo importar la lista. Revisa el archivo y vuelve a intentarlo.');
      };
      request.onerror = () => window.ProgresoOCR.finish('Se perdió la conexión. Comprueba si el lote se creó antes de volver a importar.');
      request.ontimeout = () => window.ProgresoOCR.finish('Se agotó el tiempo de espera. Comprueba si el lote se creó antes de reintentar.');
      request.send(new FormData(form));
    });
  });
})();
