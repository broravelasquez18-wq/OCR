/* =====================================================================
 * Verificador OCR - tabla editable de verificacion.
 * Guardar (edicion manual) y Confirmar (persistir) por fila via AJAX.
 * ===================================================================== */
(function () {
  'use strict';

  const tabla = document.getElementById('tablaVer');
  if (!tabla) return;

  tabla.querySelectorAll('tr[data-ver]').forEach((row) => {
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'verification-toggle';
    toggle.setAttribute('aria-expanded', 'false');
    const fields = [
      ['Identificación', '.input-num'],
      ['Nombres', '.input-nombres'],
      ['Apellidos', '.input-apellidos'],
    ];
    const values = fields.map(([label]) => {
      const group = document.createElement('span');
      const caption = document.createElement('span');
      caption.className = 'verification-toggle-label';
      caption.textContent = label;
      const value = document.createElement('strong');
      group.append(caption, value);
      toggle.append(group);
      return value;
    });
    const arrow = document.createElement('span');
    arrow.className = 'verification-toggle-arrow';
    arrow.textContent = 'Ver más ⌄';
    toggle.append(arrow);
    const sync = () => fields.forEach(([, selector], i) => {
      values[i].textContent = row.querySelector(selector)?.value.trim() || 'Sin dato';
    });
    sync();
    row.dataset.expanded = 'false';
    row.cells[0].prepend(toggle);
    toggle.addEventListener('click', () => {
      const expanded = row.dataset.expanded !== 'true';
      row.dataset.expanded = String(expanded);
      toggle.setAttribute('aria-expanded', String(expanded));
      arrow.textContent = expanded ? 'Ver menos ⌃' : 'Ver más ⌄';
      sync();
    });
    row.addEventListener('input', sync);
  });

  document.querySelectorAll('.form-asociar-imagen').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.busy || !form.reportValidity()) return;
      const button = form.querySelector('button[type="submit"]');
      const select = form.querySelector('select');
      const status = form.querySelector('.association-status');
      const selectedId = select.value;
      const person = select.selectedOptions[0].textContent;
      const body = new URLSearchParams(new FormData(form));
      form.dataset.busy = '1';
      button.disabled = true;
      select.disabled = true;
      status.textContent = 'Asociando imagen…';
      try {
        const response = await fetch(form.action, {
          method: 'POST', headers: { Accept: 'application/json' }, body,
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo asociar la imagen.');
        const offset = form.getBoundingClientRect().top;
        const target = tabla.querySelector('tr[data-ver="' + selectedId + '"]');
        if (target) {
          const images = document.createElement('div');
          images.className = 'verification-images';
          form.closest('tr').querySelectorAll('a:has(img)').forEach((link) => images.append(link.cloneNode(true)));
          target.querySelector('td > p.muted')?.replaceWith(images);
          const presence = target.querySelector('.verification-presence');
          if (presence) {
            const badge = document.createElement('span');
            badge.className = 'badge ok';
            badge.textContent = 'En Excel y ' + (form.dataset.origen === 'pdf' ? 'PDF' : 'imagen');
            const note = document.createElement('small');
            note.textContent = 'Documento asociado manualmente';
            presence.replaceChildren(badge, note);
          }
          setRowClass(target, 'corregido');
          setEstadoCelda(target.querySelector('.col-estado'), 'corregido', false);
        }
        status.textContent = 'Imagen asociada a ' + person + '. Puedes continuar aquí.';
        button.textContent = 'Imagen asociada';
        const sourcePresence = form.parentElement.querySelector('.document-presence');
        if (sourcePresence) sourcePresence.textContent = 'En Excel y ' + (form.dataset.origen === 'pdf' ? 'PDF' : 'imagen') + ' · asociado';
        document.querySelectorAll('.form-asociar-imagen select').forEach((other) => {
          if (other !== select) other.querySelector('option[value="' + selectedId + '"]')?.remove();
        });
        requestAnimationFrame(() => window.scrollBy(0, form.getBoundingClientRect().top - offset));
      } catch (error) {
        status.textContent = error.message || 'No se pudo confirmar la asociación. Revisa la conexión.';
        button.disabled = false;
        select.disabled = false;
        delete form.dataset.busy;
      }
    });
  });

  const ESTADO_TXT = {
    ok: 'OK',
    corregido: 'Corregido',
    no_coincide: 'Revisar coincidencia',
    falta_documento: 'Falta documento',
  };

  function soloDigitos(s) { return (s || '').replace(/\D/g, ''); }

  function setEstadoCelda(td, estado, confirmado) {
    let html = '<span class="badge ' + estado + '">' + (ESTADO_TXT[estado] || estado) + '</span>';
    if (confirmado) html += ' <span class="badge ok">✓</span>';
    td.innerHTML = html;
  }

  function setRowClass(tr, estado) {
    tr.className = tr.className.replace(/row-\w+/g, '').trim();
    tr.classList.add('row-' + estado);
  }

  async function post(url, params) {
    const body = new URLSearchParams(params);
    const resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok && data.ok, status: resp.status, data };
  }

  function fechaDe(tr) {
    const f = tr.querySelector('.input-fecha');
    return f ? f.value : '';
  }

  function datosDe(tr) {
    return {
      numero: soloDigitos(tr.querySelector('.input-num').value),
      nombres: tr.querySelector('.input-nombres').value.trim(),
      apellidos: tr.querySelector('.input-apellidos').value.trim(),
      fecha: fechaDe(tr),
      observacion: tr.querySelector('.input-observacion').value.trim(),
    };
  }

  function actualizarFaltantes(tr) {
    const fields = [['.input-num', 'Identificación'], ['.input-nombres', 'Nombres'], ['.input-apellidos', 'Apellidos'], ['.input-fecha', 'Fecha de nacimiento (edad sin calcular)']];
    const missing = [];
    fields.forEach(([selector, label]) => {
      const input = tr.querySelector(selector);
      const absent = !input.value.trim() || !input.validity.valid;
      input.classList.toggle('campo-faltante', absent);
      if (absent) missing.push(label);
    });
    tr.dataset.incomplete = missing.length ? '1' : '0';
    const cell = tr.querySelector('.col-faltantes');
    cell.textContent = missing.length ? 'Falta: ' + missing.join(', ') : 'Campos llenos · comprueba su contenido';
    return missing;
  }

  function validar(tr) {
    return Array.from(tr.querySelectorAll('input')).every((input) => input.reportValidity());
  }

  // --- Guardar edicion manual ---
  async function guardar(tr) {
    if (!validar(tr)) return null;
    const verId = tr.dataset.ver;
    const input = tr.querySelector('.input-num');
    const numero = soloDigitos(input.value);
    input.value = numero;

    let res;
    try {
      res = await post((window.APP_BASE || '') + '/verificaciones/' + verId + '/guardar', datosDe(tr));
    } catch (error) {
      alert('No se pudo guardar. Revisa la conexión e inténtalo de nuevo.');
      return null;
    }
    if (!res.ok) {
      alert('No se pudo guardar: ' + (res.data.error || res.status));
      return null;
    }
    setEstadoCelda(tr.querySelector('.col-estado'), res.data.estado, false);
    tr.querySelector('.name-comparison-warning')?.remove();
    setRowClass(tr, res.data.estado);
    actualizarFaltantes(tr);
    tr.querySelector('.col-faltantes').append(' · Guardado');
    tr.querySelector('.col-edad').textContent = res.data.edad != null ? res.data.edad + ' años' : 'Sin calcular';
    return res.data.estado;
  }

  // --- Confirmar (persistir) ---
  async function confirmar(tr) {
    if (!validar(tr)) return;
    const verId = tr.dataset.ver;
    const input = tr.querySelector('.input-num');
    const numero = soloDigitos(input.value);
    const numLista = tr.dataset.numlista;

    // Determinar el estado a persistir en funcion del numero final.
    let estado;
    if (numero === '') estado = 'falta_documento';
    else if (numero === numLista) estado = (tr.classList.contains('row-corregido') ? 'corregido' : 'ok');
    else estado = 'no_coincide';

    // Si el numero coincide pero la fila venia como no_coincide/falta y fue editada,
    // el backend ya la habra marcado "corregido" al guardar; respetamos coincidencia.
    if (numero === numLista && tr.dataset.wasedited === '1') estado = 'corregido';

    let msg = '¿Confirmar esta fila';
    if (estado === 'falta_documento') msg += ' como FALTA DOCUMENTO';
    else if (estado === 'no_coincide') msg += ' con numero que NO coincide (' + numero + ')';
    msg += '? Los datos quedarán confirmados; podrás corregirlos después con Editar.';
    const missing = actualizarFaltantes(tr);
    if (missing.length) msg += '\nQuedarán pendientes: ' + missing.join(', ') + '. Podrás completarlos con Editar.';
    if (!confirm(msg)) return;

    let res;
    try {
      res = await post((window.APP_BASE || '') + '/verificaciones/' + verId + '/confirmar', datosDe(tr));
    } catch (error) {
      alert('No se pudo confirmar. Revisa la conexión e inténtalo de nuevo.');
      return;
    }
    if (!res.ok) {
      alert('No se pudo confirmar: ' + (res.data.error || res.status));
      return;
    }

    // Bloquear la fila (numero y fecha).
    input.disabled = true;
    const fInput = tr.querySelector('.input-fecha');
    if (fInput) fInput.disabled = true;
    tr.querySelectorAll('.input-nombres, .input-apellidos').forEach((field) => { field.disabled = true; });
    tr.querySelector('.input-observacion').disabled = true;
    tr.dataset.confirmado = '1';
    setEstadoCelda(tr.querySelector('.col-estado'), res.data.estado, true);
    setRowClass(tr, res.data.estado);
    tr.querySelector('.col-accion').innerHTML = '<span class="muted">Confirmado</span><button class="btn secondary btn-editar" type="button">Editar</button>';

    if (res.data.todasConfirmadas) {
      const btnCerrar = document.getElementById('btnCerrar');
      const hint = document.getElementById('cerrarHint');
      if (btnCerrar) btnCerrar.disabled = false;
      if (hint) hint.style.display = 'none';
    }
  }

  // --- Delegacion de eventos ---
  async function reabrir(tr, button) {
    if (!confirm('¿Editar esta fila? Se conservarán los datos y tendrás que confirmarla nuevamente. Si el lote estaba cerrado, se reabrirá.')) return;
    button.disabled = true;
    try {
      const res = await post((window.APP_BASE || '') + '/verificaciones/' + tr.dataset.ver + '/reabrir', {});
      if (!res.ok) throw new Error(res.data.error || 'No se pudo habilitar la edición.');
      location.reload();
    } catch (error) {
      alert(error.message || 'Revisa la conexión e inténtalo nuevamente.');
      button.disabled = false;
    }
  }

  tabla.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-ver]');
    if (!tr) return;
    if (e.target.classList.contains('btn-guardar')) guardar(tr);
    else if (e.target.classList.contains('btn-confirmar')) confirmar(tr);
    else if (e.target.classList.contains('btn-editar')) reabrir(tr, e.target);
  });

  // Marcar filas editadas manualmente para el calculo de estado al confirmar.
  tabla.addEventListener('input', (e) => {
    if (e.target.classList.contains('input-fecha')) {
      const cell = e.target.closest('tr').querySelector('.col-edad');
      const value = e.target.value;
      const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
      const part = (type) => parts.find((item) => item.type === type).value;
      const today = `${part('year')}-${part('month')}-${part('day')}`;
      if (!value || value > today) {
        cell.textContent = 'Sin calcular';
      } else {
        const age = Number(today.slice(0, 4)) - Number(value.slice(0, 4)) - (today.slice(5) < value.slice(5) ? 1 : 0);
        cell.textContent = age + ' años';
      }
    }
    if (e.target.classList.contains('input-num')) {
      const tr = e.target.closest('tr[data-ver]');
      if (tr) tr.dataset.wasedited = '1';
    }
    const row = e.target.closest('tr[data-ver]');
    if (row) {
      actualizarFaltantes(row);
      row.querySelector('.col-faltantes').append(' · Cambios sin guardar');
    }
  });

  tabla.querySelectorAll('tr[data-ver]').forEach(actualizarFaltantes);

  // --- Cerrar lote ---
  const btnCerrar = document.getElementById('btnCerrar');
  if (btnCerrar) {
    btnCerrar.addEventListener('click', async () => {
      if (!confirm('¿Cerrar el lote? Ya no se editaran las filas.')) return;
      const res = await post((window.APP_BASE || '') + '/lotes/' + btnCerrar.dataset.lote + '/cerrar', {});
      if (!res.ok) {
        alert('No se pudo cerrar: ' + (res.data.error || res.status));
        return;
      }
      alert('Lote cerrado.');
      window.location.href = (window.APP_BASE || '') + '/lotes/' + btnCerrar.dataset.lote + '/informe';
    });
  }
})();
