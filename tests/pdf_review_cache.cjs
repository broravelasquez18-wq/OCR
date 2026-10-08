const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(blobSize = 1000, pageCount = 2) {
  class Element {
    constructor() {
      this.events = {}; this.children = []; this.files = [];
      this.classList = { add() {}, remove() {}, toggle() {} };
    }
    addEventListener(name, callback) { this.events[name] = callback; }
    append(...children) { this.children.push(...children); }
    replaceChildren() { this.children = []; }
    setAttribute() {}
    getContext() { return { drawImage() {} }; }
    toBlob(callback) { callback({ size: blobSize }); }
  }
  const elements = new Map();
  const get = id => {
    if (!elements.has(id)) elements.set(id, new Element());
    return elements.get(id);
  };
  const counts = { opens: 0, renders: 0, destroys: 0 };
  const file = { arrayBuffer: async () => new ArrayBuffer(1) };
  get('pdfFile').files = [file];
  const alerts = [];
  const window = {
    APP_BASE: '/OCR/public', ProgresoOCR: { busy: false },
    pdfjsLib: {
      GlobalWorkerOptions: {},
      getDocument() {
        counts.opens++;
        return { promise: Promise.resolve({
          numPages: pageCount,
          getPage: async () => ({
            getViewport: ({ scale }) => ({ width: 600 * scale, height: 800 * scale }),
            render() { counts.renders++; return { promise: Promise.resolve() }; },
            cleanup() {},
          }),
          destroy: async () => { counts.destroys++; },
        }) };
      },
    },
  };
  vm.runInNewContext(fs.readFileSync('public/assets/js/pdf-review.js', 'utf8'), {
    window, document: { getElementById: get, createElement: () => new Element() },
    alert: message => alerts.push(message),
    fetch: async () => ({}),
  });
  return { window, get, file, counts, alerts };
}

(async () => {
  const a = setup();
  await a.get('pdfPrepare').events.click();
  assert.equal(a.get('btnPdf').disabled, false, 'Automático no exige marcar páginas');
  assert.equal(a.window.PdfReview.plan(a.file).length, 2);
  a.get('pdfManual').checked = true;
  a.get('pdfManual').events.change();
  assert.equal(a.get('btnPdf').disabled, true, 'Manual exige agrupación explícita');
  a.get('pdfCompleteRemaining').events.click();
  assert.equal(a.window.PdfReview.plan(a.file).length, 2);
  const first = await a.window.PdfReview.image(a.file, 1);
  assert.equal(await a.window.PdfReview.image(a.file, 1), first);
  await a.window.PdfReview.image(a.file, 2);
  assert.deepEqual(a.counts, { opens: 1, renders: 2, destroys: 0 });
  assert.equal(a.window.pdfjsLib.GlobalWorkerOptions.workerSrc, '/OCR/public/assets/vendor/pdfjs/pdf.worker.min.js');
  a.window.PdfReview.started();
  a.window.PdfReview.finished();
  assert.equal(a.counts.destroys, 1);
  await assert.rejects(a.window.PdfReview.image({}, 1), /PDF cambió/);

  // El exceso de memoria se renderiza desde el PDF retenido, sin reabrirlo.
  const b = setup(70 * 1024 * 1024);
  await b.get('pdfPrepare').events.click();
  await b.window.PdfReview.image(b.file, 1);
  assert.equal(b.counts.opens, 1);
  assert.equal(b.counts.renders, 3);
  b.get('pdfFile').events.change();
  assert.equal(b.counts.destroys, 1);
  await assert.rejects(b.window.PdfReview.image(b.file, 1), /PDF cambió/);

  const c = setup();
  delete c.window.pdfjsLib;
  await c.get('pdfPrepare').events.click();
  assert.match(c.alerts[0], /No se pudo cargar el lector PDF/);
  assert.equal(c.get('pdfPrepare').disabled, false);
  const odd = setup(1000, 3);
  await odd.get('pdfPrepare').events.click();
  assert.equal(odd.get('btnPdf').disabled, false);
  assert.equal(odd.window.PdfReview.plan(odd.file).length, 3);
  console.log('OK: reutilización de imágenes, límite de caché, liberación y error de carga.');
})().catch(error => { console.error(error); process.exitCode = 1; });
