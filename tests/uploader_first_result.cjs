const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
const get = id => {
  if (!elements.has(id)) elements.set(id, { events: {}, style: {}, textContent: '0', files: [{ name: 'sample.pdf' }],
    addEventListener(event, callback) { this.events[event] = callback; } });
  return elements.get(id);
};
let active = 0, maximum = 0, sent = 0, completed = 0, steps = 0, finished = false;
let compared = false;
const starts = [];
class Request {
  constructor() { this.upload = {}; }
  open() {}
  send() {
    starts.push({ completed, active });
    active++; maximum = Math.max(maximum, active);
    const id = ++sent;
    this.upload.onload();
    setTimeout(() => {
      active--; completed++;
      this.status = 200;
      this.responseText = JSON.stringify({ ok: true, documento_id: id, numero: '123', nombres: 'A',
        apellidos: 'B', fecha_nacimiento: '2000-01-01', edad: 26 });
      this.onload();
    }, 10);
  }
}
const window = { LOTE_ID: 1, APP_BASE: '/OCR/public', addEventListener() {},
  ProgresoOCR: { begin: () => true, phase() {}, step: () => steps++, finish: () => { finished = true; }, error: assert.fail },
  PdfReview: { automatic:()=>true, plan: () => [[1], [2], [3]], started() {}, finished() {}, image: async () => ({}) },
};
vm.runInNewContext(fs.readFileSync('public/assets/js/uploader.js', 'utf8'), {
  window, document: { getElementById: get, querySelector: () => null, querySelectorAll: () => [] },
  FormData: class { append() {} }, XMLHttpRequest: Request,
  setTimeout, performance, alert: assert.fail,
  URLSearchParams, AbortSignal,
  fetch: async (url, options) => {
    assert.equal(completed, 3, 'Agrupar después de guardar todas las páginas');
    if (url.endsWith('/ocr/finalizar')) {
      assert.equal(finished,false,'Comparar antes de entregar resultados');
      compared = true;
      return {ok:true,json:async()=>({ok:true,resumen:{}})};
    }
    assert.match(url, /ocr\/agrupar-pdf$/);
    assert.deepEqual(JSON.parse(options.body.get('documentos')), [1,2,3]);
    return {ok:true,json:async()=>({ok:true,pares:1,documentos:2})};
  },
});
(async () => {
  await get('btnPdf').events.click();
  assert.equal(sent, 3);
  assert.equal(starts[0].completed, 0);
  assert.equal(starts[1].completed, 1);
  assert.equal(starts[2].completed, 1);
  assert.equal(maximum, 2);
  assert.equal(steps, 3);
  assert.equal(finished, true);
  assert.equal(compared,true,'Comparación automática obligatoria');
  assert.equal(window.OCR_TIMINGS.length, 3);
  assert.equal(get('docCount').textContent,'2');
  console.log('OK: primer documento prioritario, dos motores después y progreso real.');
})().catch(error => { console.error(error); process.exitCode = 1; });
