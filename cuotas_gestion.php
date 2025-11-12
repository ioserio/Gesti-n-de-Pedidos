<?php
require_once __DIR__ . '/require_login.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Gestión de Cuotas</title>
<link rel="stylesheet" href="estilos.css" />
<style>
.cuotas-wrap{max-width:1000px;margin:20px auto;padding:16px;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.08);border-radius:8px;font-family:Arial,sans-serif;}
.cuotas-wrap h1{margin:0 0 12px;font-size:24px;color:#222;}
.cuotas-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;align-items:end;}
.cuotas-form label{display:flex;flex-direction:column;font-size:13px;font-weight:bold;color:#333;gap:4px;}
.cuotas-form input[type=text],.cuotas-form input[type=number],.cuotas-form input[type=date],.cuotas-form select{padding:6px 8px;border:1px solid #bbb;border-radius:4px;font-size:14px;}
.cuotas-form .week-box{display:flex;align-items:center;gap:6px;margin-top:4px;font-weight:normal;font-size:13px;}
.cuotas-form button{padding:10px 18px;background:#007bff;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,.15);} 
.cuotas-form button:hover{background:#005fcc;}
#cuotas-msg{margin-bottom:10px;font-size:13px;}
.table-zone{overflow-x:auto;}
.table-zone table{border-collapse:collapse;width:100%;margin-top:6px;font-size:13px;}
.table-zone th, .table-zone td{border:1px solid #ddd;padding:6px 8px;text-align:left;}
.table-zone th{background:#f0f6ff;}
.del-btn{background:#dc3545;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px;} .del-btn:hover{background:#b52a38;}
.hist-zone{margin-top:28px;}
.hist-zone h2{font-size:18px;margin:0 0 8px;}
.hist-list{font-size:13px;max-height:240px;overflow:auto;border:1px solid #ddd;padding:8px;border-radius:6px;background:#fafafa;}
.hist-item{padding:4px 6px;border-bottom:1px solid #e1e1e1;display:flex;justify-content:space-between;gap:12px;}
.hist-item:last-child{border-bottom:none;}
@media (max-width:700px){
  .cuotas-form{grid-template-columns:repeat(auto-fit,minmax(140px,1fr));}
  .cuotas-wrap{margin:6px;padding:12px;}
}
</style>
</head>
<body>
<div class="cuotas-wrap">
  <h1>Gestión de Cuotas (Semanal / Histórico)</h1>
  <p style="font-size:13px;color:#555;">Registra una cuota para un día específico o aplica el mismo valor a toda la semana. Cada registro se guarda en el histórico sin eliminar los anteriores.</p>
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
    <button type="button" id="btn-add-mass" style="background:#28a745;">Agregar cuotas</button>
    <button type="button" id="btn-save-mass" style="background:#17a2b8;">Guardar cambios</button>
    <span id="mass-msg" style="font-size:12px; color:#555;"></span>
  </div>
  <div id="cuotas-msg"></div>
  <form id="form-cuotas" class="cuotas-form">
    <label>Cod. Vendedor
      <input type="text" name="cod_vendedor" id="cod_vendedor" required maxlength="10" placeholder="Ej: 011" />
    </label>
    <label>Cuota (S/)
      <input type="number" step="0.01" min="0" name="cuota" id="cuota" required />
    </label>
    <label>Fecha vigente desde
      <input type="date" name="vigente_desde" id="vigente_desde" required />
    </label>
    <label>Día semana
      <select name="dia_semana" id="dia_semana">
        <option value="1">Lunes</option>
        <option value="2">Martes</option>
        <option value="3">Miércoles</option>
        <option value="4">Jueves</option>
        <option value="5">Viernes</option>
        <option value="6">Sábado</option>
        <option value="7">Domingo</option>
      </select>
      <div class="week-box">
        <input type="checkbox" id="full_week" name="full_week" /> <label for="full_week" style="font-weight:normal;">Aplicar a toda la semana</label>
      </div>
    </label>
    <div style="display:flex;align-items:center;gap:10px;">
      <button type="submit">Guardar cuota</button>
    </div>
  </form>
  <div class="table-zone">
    <div id="cuotas-table"></div>
  </div>
  <div class="table-zone" id="mass-zone" style="margin-top:16px; display:none;">
    <h2 style="font-size:18px; margin:0 0 6px;">Nuevas cuotas a registrar</h2>
  <p id="mass-help" style="font-size:12px; color:#666;">Al pulsar "Agregar cuotas" se crearán filas sin guardar todavía: primero todos los vendedores (001→997) del lunes, luego martes y así hasta sábado. Ingresa los montos y pulsa "Guardar cambios" para registrar solamente las cuotas &gt; 0. El campo "Vigente desde" fija el lunes de la semana actual.</p>
    <div id="mass-table"></div>
  </div>
  <div class="hist-zone">
    <h2>Histórico por vendedor y día</h2>
    <p style="font-size:12px;color:#666;">Ingresa un código y día para ver todos los registros almacenados.</p>
    <form id="form-hist" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
      <label style="flex:1;min-width:140px;">Cod. Vendedor
        <input type="text" id="h_cod" maxlength="10" placeholder="011" />
      </label>
      <label style="flex:1;min-width:140px;">Día
        <select id="h_dia">
          <option value="1">Lunes</option>
          <option value="2">Martes</option>
          <option value="3">Miércoles</option>
          <option value="4">Jueves</option>
          <option value="5">Viernes</option>
          <option value="6">Sábado</option>
          <option value="7">Domingo</option>
        </select>
      </label>
      <button type="submit" style="height:38px;">Ver histórico</button>
    </form>
    <div class="hist-list" id="hist-list"></div>
  </div>
</div>
<script>
let currentSort = 'cod';
// Orden interactivo en listado vigente
document.addEventListener('click', function(e){
  const a = e.target.closest('.q-sort');
  if (!a) return;
  e.preventDefault();
  const sort = a.getAttribute('data-sort');
  currentSort = sort || 'cod';
  fetch('cuotas_api.php?action=list&sort=' + encodeURIComponent(currentSort))
    .then(r=>r.text())
    .then(html=>{ document.getElementById('cuotas-table').innerHTML = html; hookDelete(); })
    .catch(()=>{});
});
function mondayOfCurrentWeek(){
  const d = new Date();
  const day = d.getDay(); // 0=Domingo
  const diff = d.getDate() - day + (day === 0 ? -6 : 1); // ajustar lunes
  const monday = new Date(d.setDate(diff));
  monday.setHours(0,0,0,0);
  return monday.toISOString().slice(0,10);
}
const vigenteInput = document.getElementById('vigente_desde');
if(vigenteInput){ vigenteInput.value = mondayOfCurrentWeek(); }

function loadCuotas(){
  fetch('cuotas_api.php?action=list&sort=' + encodeURIComponent(currentSort))
    .then(r=>r.text())
    .then(html=>{ document.getElementById('cuotas-table').innerHTML = html; hookDelete(); })
    .catch(()=>{ document.getElementById('cuotas-table').innerHTML = '<p>Error cargando cuotas.</p>'; });
}
function hookDelete(){
  document.querySelectorAll('[data-del="1"]').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const cod = btn.getAttribute('data-cod');
      const dia = btn.getAttribute('data-dia');
      if(!confirm('¿Eliminar cuota vigente actual para vendedor '+cod+' día '+dia+'?')) return;
      fetch('cuotas_api.php?action=delete&cod='+encodeURIComponent(cod)+'&dia='+encodeURIComponent(dia)+'&sort='+encodeURIComponent(currentSort))
        .then(r=>r.text())
        .then(()=>loadCuotas());
    });
  });
}

const form = document.getElementById('form-cuotas');
form.addEventListener('submit', e => {
  e.preventDefault();
  const fd = new FormData(form);
  fd.append('action','save');
  fetch('cuotas_api.php?action=save&sort='+encodeURIComponent(currentSort),{method:'POST',body:fd})
    .then(r=>r.text())
    .then(html=>{
      document.getElementById('cuotas-msg').innerHTML = '<span style="color:green;">Guardado</span>';
      // tras guardar, recargar tabla con el orden actual para ser consistentes
      loadCuotas();
    })
    .catch(()=>{ document.getElementById('cuotas-msg').innerHTML = '<span style="color:red;">Error</span>'; });
});

// Histórico simple (usa tabla histórica directamente)
const histForm = document.getElementById('form-hist');
histForm.addEventListener('submit', e => {
  e.preventDefault();
  const cod = document.getElementById('h_cod').value.trim();
  const dia = document.getElementById('h_dia').value;
  if(!cod){ document.getElementById('hist-list').innerHTML='<p>Ingrese código.</p>'; return; }
  fetch('cuotas_hist_api.php?cod='+encodeURIComponent(cod)+'&dia='+encodeURIComponent(dia))
    .then(r=>r.json())
    .then(data=>{
      if(!Array.isArray(data) || data.length===0){ document.getElementById('hist-list').innerHTML='<p>Sin registros.</p>'; return; }
      const frag = data.map(row => `<div class="hist-item"><span><strong>${row.vigente_desde}</strong> — S/ ${parseFloat(row.Cuota).toFixed(2)}</span><span style="color:#666;">${row.created_at}</span></div>`).join('');
      document.getElementById('hist-list').innerHTML = frag;
    })
    .catch(()=>{ document.getElementById('hist-list').innerHTML='<p>Error.</p>'; });
});

loadCuotas();

// Generación masiva de plantilla
const btnAddMass = document.getElementById('btn-add-mass');
const btnSaveMass = document.getElementById('btn-save-mass');
const massZone = document.getElementById('mass-zone');
const massTable = document.getElementById('mass-table');
const massMsg = document.getElementById('mass-msg');
const massHelp = document.getElementById('mass-help');
const dayNames = {1:'Lunes', 2:'Martes', 3:'Miércoles', 4:'Jueves', 5:'Viernes', 6:'Sábado'};

function buildMassTemplate(){
  const lunes = mondayOfCurrentWeek();
  massMsg.textContent = 'Cargando lista de vendedores...';
  fetch('vendors_api.php', { headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' } })
    .then(async r => {
      const text = await r.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        const snippet = (text || '').slice(0,200);
        throw new Error(snippet || ('HTTP '+r.status));
      }
      return data;
    })
    .then(data => {
      if (!data || data.ok !== true || !Array.isArray(data.vendors)) {
        throw new Error('No se pudo obtener vendedores');
      }
      const vendors = data.vendors; // [{cod, nombre}]
      const vCount = vendors.length;
      const totalRows = vCount * 6; // Lunes-Sábado
      const rows = [];
      rows.push('<table><thead><tr><th>Cod_Vendedor</th><th>Nombre</th><th>Día</th><th>Cuota (S/)</th><th>Vigente desde</th></tr></thead><tbody>');
      for (let d=1; d<=6; d++) {
        for (let i=0; i<vendors.length; i++) {
          const cod = vendors[i].cod || '';
          const nom = vendors[i].nombre ? String(vendors[i].nombre) : '';
          rows.push('<tr class="mass-row" data-cod="'+cod+'" data-dia="'+d+'">'
            + '<td>'+cod+'</td>'
            + '<td>'+ (nom ? nom.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '') +'</td>'
            + '<td>'+dayNames[d]+'</td>'
            + '<td><input type="number" step="0.01" min="0" class="mass-cuota" style="width:110px;"></td>'
            + '<td><input type="date" class="mass-vigente" value="'+lunes+'" readonly></td>'
            + '</tr>');
        }
      }
      rows.push('</tbody></table>');
      massTable.innerHTML = rows.join('');
      massZone.style.display = 'block';
  massMsg.textContent = 'Plantilla generada: Lunes→Sábado con ' + vCount + ' vendedores (total ' + totalRows.toLocaleString('es-PE') + ' filas). Ingresa montos y luego pulsa "Guardar cambios".';
    })
    .catch(err => {
      massMsg.textContent = 'No se pudo cargar vendedores ('+(err && err.message ? err.message : 'error')+').';
    });
}

if (btnAddMass) btnAddMass.addEventListener('click', buildMassTemplate);

if (btnSaveMass) btnSaveMass.addEventListener('click', function(){
  const rows = Array.from(document.querySelectorAll('#mass-table .mass-row'));
  if (!rows.length) { massMsg.textContent = 'No hay filas para guardar.'; return; }
  // Construir payload solo con cuotas > 0
  const items = [];
  rows.forEach(tr => {
    const cuotaEl = tr.querySelector('.mass-cuota');
    const vigEl = tr.querySelector('.mass-vigente');
    const val = parseFloat(cuotaEl && cuotaEl.value || '0');
    if (isNaN(val) || val <= 0) return;
    items.push({
      cod: tr.getAttribute('data-cod'),
      dia: parseInt(tr.getAttribute('data-dia'),10)||0,
      cuota: val,
      vigente_desde: vigEl ? vigEl.value : mondayOfCurrentWeek()
    });
  });
  if (!items.length) { massMsg.textContent = 'No hay montos > 0 para guardar.'; return; }
  massMsg.textContent = 'Guardando ' + items.length + ' filas...';
  const fd = new FormData();
  fd.append('action','bulk');
  fd.append('items', JSON.stringify(items));
  fetch('cuotas_api.php?action=bulk', { method:'POST', body: fd })
    .then(r=>r.json())
    .then(j => {
      if (!j || j.ok !== true) throw new Error(j && j.error || 'Error');
      massMsg.textContent = 'Guardadas: ' + j.saved + '. Omitidas: ' + j.skipped + '. Recalculando lista vigente...';
      loadCuotas();
    })
    .catch(err => { massMsg.textContent = 'Error: ' + (err.message||''); });
});
</script>
</body>
</html>
