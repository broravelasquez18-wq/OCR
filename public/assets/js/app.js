(() => {
  'use strict';
  const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  document.querySelectorAll('[data-table-search]').forEach((search) => {
    const tableId = search.dataset.tableSearch;
    const table = document.getElementById(tableId);
    if (!table || !table.tBodies[0]) return;
    const status = document.querySelector(`[data-table-status="${tableId}"]`);
    const incomplete = tableId === 'tablaVer' ? document.getElementById('soloDatosFaltantes') : null;
    const count = document.querySelector(`[data-result-count="${tableId}"]`);
    const empty = document.querySelector(`[data-empty-for="${tableId}"]`);
    const update = () => {
      const rows = Array.from(table.tBodies[0].rows);
      const query = normalize(search.value.trim());
      const selected = status ? status.value : '';
      let visible = 0;
      rows.forEach((row) => {
        const textMatch = normalize(row.textContent).includes(query);
        const statusMatch = !selected || (selected === 'pendiente' ? row.dataset.confirmado !== '1' : row.classList.contains(`row-${selected}`));
        row.hidden = !(textMatch && statusMatch && (!incomplete || !incomplete.checked || row.dataset.incomplete === '1'));
        if (!row.hidden) visible += 1;
      });
      if (count) count.textContent = `${visible} de ${rows.length} resultados`;
      if (empty) empty.hidden = visible > 0 || rows.length === 0;
    };
    search.addEventListener('input', update);
    if (status) status.addEventListener('change', update);
    if (incomplete) incomplete.addEventListener('change', update);
    new MutationObserver(update).observe(table.tBodies[0], { attributes: true, subtree: true, attributeFilter: ['class', 'data-confirmado', 'data-incomplete'], childList: true });
    update();
  });
  document.querySelectorAll('input.input-num, input.input-fecha').forEach((input) => {
    const name = input.closest('tr').cells[0].textContent.trim();
    const label = input.classList.contains('input-num') ? 'Número corregido' : 'Fecha de nacimiento';
    input.setAttribute('aria-label', `${label} de ${name}`);
  });
  // Confirmacion para formularios destructivos (p. ej. eliminar lote).
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) {
        event.preventDefault();
      }
    });
  });
})();
