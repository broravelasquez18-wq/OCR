const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function scenario(mode = 'ok') {
  const events = [], elements = new Map();
  const get = id => {
    if (!elements.has(id)) elements.set(id, {
      files: [], hidden: true, textContent: '', style: {}, events: {},
      addEventListener(name, callback) { this.events[name] = callback; },
    });
    return elements.get(id);
  };
  const form = get('form');
  form.action = '/OCR/public/lotes/importar';
  form.querySelector = () => get('submit');
  form.reportValidity = () => true;
  get('lista').files = [{ name: 'lista.xls' }];
  get('pdfFiles').files = ['uno.pdf', 'dos.pdf'].map(name => ({ name, arrayBuffer: async () => name }));
  let imports = 0, uploads = 0, finishedError, steps = [], busy = false, total;
  class Body extends Map { append(key, value) { this.set(key, value); } }
  class Request {
    constructor() { this.upload = {}; }
    open(method, url) { this.url = url; }
    setRequestHeader() {}
    send(body) {
      if (this.url.endsWith('/importar')) {
        imports++; events.push('import');
        assert.deepEqual(Array.from(body.keys()), ['lista']);
        if (mode === 'timeout') { this.ontimeout(); return; }
        if (mode === 'invalid-response') { this.status = 200; this.responseText = '<html>'; this.onload(); return; }
        this.status = mode === 'excel-error' ? 422 : 200;
        this.responseText = JSON.stringify(mode === 'excel-error' ? { ok: false, error: 'Ficha duplicada' } : { ok: true, lote_id: 17 });
      } else {
        assert.equal(body.get('lote_id'), 17);
        uploads++; events.push('upload');
        this.status = 200;
        this.responseText = JSON.stringify({ ok: true, documento_id: uploads, numero: '123', nombres: 'ANA', apellidos: 'PRUEBA', fecha_nacimiento: '2000-01-01', edad: 26 });
        if (mode === 'page-error' && uploads === 1) { this.status = 422; this.responseText = JSON.stringify({ok:false,error:'Imagen ilegible'}); }
      }
      this.onload();
    }
  }
  const window = {
    APP_BASE: '/OCR/public',
    ProgresoOCR: {
      get busy() { return busy; },
      begin() { if (busy) return false; busy = true; return true; },
      phase() {}, total(n) { total = n; }, error() {},
      step(result) { steps.push(result); }, finish(error) { finishedError = error; busy = false; events.push('finish'); },
    },
    pdfjsLib: {
      GlobalWorkerOptions: {},
      getDocument({data}) {
        events.push('open:' + data);
        const task = {
          destroy: async () => events.push('destroy-task'),
          promise: mode === 'pdf-error' && data === 'dos.pdf' ? Promise.reject(new Error('Invalid PDF')) : Promise.resolve({
            numPages: 2,
            destroy: async () => events.push('destroy:' + data),
            getPage: async () => ({ getViewport: () => ({width:100,height:100}), render: () => ({promise:Promise.resolve()}), cleanup() {} }),
          }),
        };
        if (mode === 'password') {
          task.promise = new Promise(() => {});
          queueMicrotask(() => task.onPassword());
        }
        return task;
      },
    },
  };
  const groups = [];
  const context = {
    window, document: {
      querySelector: () => form, getElementById: id => ['docCount','progressWrap','progressBar','pdfReviewStatus'].includes(id) ? null : get(id),
      createElement: () => ({getContext:()=>({}),toBlob:callback=>callback({})}),
    },
    FormData: Body, XMLHttpRequest: Request, performance, URLSearchParams, AbortSignal, setTimeout,
    fetch: async (url, options) => {
      if (url.endsWith('/agrupar-pdf')) {
        const ids = JSON.parse(options.body.get('documentos'));
        groups.push(ids); events.push('group');
        return {ok:true,json:async()=>({ok:true,documentos:uploads,pares:1})};
      }
      assert.ok(url.endsWith('/finalizar'));
      assert.equal(uploads,4,'Comparar después de atender todos los PDF');
      events.push('compare');
      return {ok:mode!=='compare-error',json:async()=>({ok:mode!=='compare-error'})};
    },
  };
  vm.createContext(context);
  for (const path of ['uploader.js','carga-conjunta.js']) vm.runInContext(fs.readFileSync('public/assets/js/'+path,'utf8'), context);
  await form.events.submit({preventDefault(){}});
  if (['pdf-error','password'].includes(mode)) {
    assert.equal(imports,0,'No crear lote con PDF inválido/protegido');
    assert.equal(get('submit').disabled,false);
    assert.ok(finishedError);
  } else if (['excel-error','timeout','invalid-response'].includes(mode)) {
    assert.equal(uploads,0,'No procesar OCR sin lote confirmado');
    assert.equal(get('btnIrVerificacion').hidden,true);
    assert.equal(get('submit').disabled,mode!=='excel-error');
    assert.ok(finishedError);
  } else {
    assert.equal(imports,1); assert.equal(uploads,4); assert.equal(total,4); assert.equal(steps.length,4);
    assert.ok(events.indexOf('open:dos.pdf') < events.indexOf('import'));
    assert.ok(events.indexOf('import') < events.indexOf('upload'));
    assert.ok(events.indexOf('compare') < events.indexOf('finish'));
    assert.deepEqual(groups, mode === 'page-error' ? [[3,4]] : [[1,2],[3,4]], 'Agrupar dentro de cada PDF');
    assert.equal(get('btnIrVerificacion').href,'/OCR/public/lotes/17/verificacion');
    assert.equal(get('continuarDocumentos').hidden,false);
    assert.equal(get('submit').disabled,true);
    assert.equal(Boolean(finishedError),mode==='compare-error');
    if (mode==='page-error') assert.ok(steps.includes('error'));
    await form.events.submit({preventDefault(){}});
    assert.equal(imports,1,'Impedir repetir la importación después de guardar');
  }
  if (!['password'].includes(mode)) assert.ok(events.includes('destroy:uno.pdf'),'Liberar PDF al terminar');
}
(async () => {
  for (const mode of ['ok','pdf-error','password','excel-error','timeout','invalid-response','page-error','compare-error']) await scenario(mode);
  console.log('OK: carga conjunta, varios PDF, comparación, errores parciales, validación y prevención de duplicados.');
})().catch(error => {console.error(error);process.exitCode=1;});
