// Función para poner la fecha de hoy en el input de resumen
function setFechaHoyResumen() {
    const fechaInput = document.getElementById('fecha_resumen');
    if (fechaInput) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
}

    // Función para poner la fecha de hoy en el input de devoluciones
    function setFechaHoyDevoluciones() {
        const fechaInput = document.getElementById('fecha_dev');
        if (fechaInput) {
            const hoy = new Date();
            const yyyy = hoy.getFullYear();
            const mm = String(hoy.getMonth() + 1).padStart(2, '0');
            const dd = String(hoy.getDate()).padStart(2, '0');
            fechaInput.value = `${yyyy}-${mm}-${dd}`;
        }
    }

    // Función para poner la fecha de hoy en los inputs de recojos (si existen)
    function setFechaHoyRecojos() {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        const val = `${yyyy}-${mm}-${dd}`;
        const f1 = document.getElementById('fecha_recojo_desde');
        const f2 = document.getElementById('fecha_recojo_hasta');
        if (f1) f1.value = val;
        if (f2) f2.value = val;
        const fSingle = document.getElementById('fecha_recojo');
        if (fSingle) fSingle.value = val; // compatibilidad si existiera
    }

// Mostrar módulo y cargar resumen automáticamente
window.mostrarModulo = function(modulo) {
    document.getElementById('modulo-subir').style.display = (modulo === 'subir') ? 'block' : 'none';
    document.getElementById('modulo-consultar').style.display = (modulo === 'consultar') ? 'block' : 'none';
    document.getElementById('modulo-resumen').style.display = (modulo === 'resumen') ? 'block' : 'none';
    const cobr = document.getElementById('modulo-cobranzas');
    if (cobr) cobr.style.display = (modulo === 'cobranzas') ? 'block' : 'none';
    // Alternar modo ancho completo solo para cobranzas
    const wrap = document.querySelector('.container');
    if (wrap) {
        if (modulo === 'cobranzas') wrap.classList.add('fullwidth');
        else wrap.classList.remove('fullwidth');
    }
    const admin = document.getElementById('modulo-admin');
    if (admin) admin.style.display = (modulo === 'admin') ? 'block' : 'none';
        const devol = document.getElementById('modulo-devoluciones');
        if (devol) devol.style.display = (modulo === 'devoluciones') ? 'block' : 'none';
        const recojos = document.getElementById('modulo-recojos');
        if (recojos) recojos.style.display = (modulo === 'recojos') ? 'block' : 'none';
    if (modulo === 'resumen') {
        // Esperar a que el DOM renderice el input de fecha
        setTimeout(function() {
            setFechaHoyResumen();
            const fechaInput = document.getElementById('fecha_resumen');
            if (fechaInput) {
                cargarResumen(fechaInput.value);
            }
            // Cargar la etiqueta de última actualización
            try { cargarUltimaActualizacion(); } catch(_) {}
        }, 100);
    } else if (modulo === 'admin') {
        // Cargar lista de cuotas
        setTimeout(function(){ cargarCuotas(); }, 50);
        } else if (modulo === 'devoluciones') {
            setTimeout(function(){
                setFechaHoyDevoluciones();
                const f = document.getElementById('fecha_dev');
                if (f) cargarDevoluciones();
            }, 100);
        } else if (modulo === 'recojos') {
            setTimeout(function(){
                setFechaHoyRecojos();
                const cont = document.getElementById('recojos');
                if (cont) cont.innerHTML = '<p>Ingrese Vendedor y presione Consultar.</p>';
                const f = document.getElementById('fecha_recojo');
                if (f) cargarRecojos();
            }, 100);
        } else if (modulo === 'cobranzas') {
            setTimeout(function(){
                const cont = document.getElementById('cobranzas');
                if (cont) cont.innerHTML = '<p>Ingrese el Vendedor y presione Consultar, o deje vacío para ver todos.</p>';
            }, 50);
    }
}

    function cargarUltimaActualizacion(){
        const lbl = document.getElementById('ultima_actualizacion');
        if (!lbl) return;
        fetch('ultima_actualizacion.php')
            .then(r => r.text())
            .then(txt => { lbl.textContent = txt; })
            .catch(() => { lbl.textContent = 'Ultima actualizacion: -'; });
    }
function cargarResumen(fecha) {
    const resumen = document.getElementById('resumen');
    const supervisor = document.getElementById('supervisor_resumen') ? document.getElementById('supervisor_resumen').value : '';
    if (resumen) {
        resumen.innerHTML = '<p>Cargando...</p>';
        let url = 'resumen_pedidos.php?fecha=' + encodeURIComponent(fecha);
        if (supervisor) {
            url += '&supervisor=' + encodeURIComponent(supervisor);
        }
        fetch(url)
            .then(res => res.text())
            .then(html => {
                resumen.innerHTML = html;
            })
            .catch(() => {
                resumen.innerHTML = '<p>Error al consultar el resumen.</p>';
            });
    }
}

// Evento para el formulario de resumen
document.addEventListener('DOMContentLoaded', function() {
    const formResumen = document.getElementById('form-resumen');
    if (formResumen) {
        formResumen.addEventListener('submit', function(e) {
            e.preventDefault();
            const fecha = document.getElementById('fecha_resumen').value;
            cargarResumen(fecha);
            // Refrescar la última actualización por si hubo cambios recientes
            try { cargarUltimaActualizacion(); } catch(_) {}
        });
        // Actualizar resumen al cambiar supervisor
        const supervisorSelect = document.getElementById('supervisor_resumen');
        if (supervisorSelect) {
            supervisorSelect.addEventListener('change', function() {
                const fecha = document.getElementById('fecha_resumen').value;
                cargarResumen(fecha);
                try { cargarUltimaActualizacion(); } catch(_) {}
            });
        }
    }
    // Mostrar por defecto el módulo de subir
    window.mostrarModulo('subir');
    // Admin: listeners
    const formCuota = document.getElementById('form-cuota');
    if (formCuota) {
        formCuota.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(formCuota);
            fd.append('action', 'save');
            fetch('cuotas_api.php', { method: 'POST', body: fd })
                .then(r => r.text())
                .then(html => {
                    document.getElementById('lista-cuotas').innerHTML = html;
                })
                .catch(() => {
                    alert('Error al guardar cuota');
                });
        });
        // Delegar eliminación
        document.getElementById('lista-cuotas').addEventListener('click', function(e){
            const btn = e.target.closest('button[data-del]');
            if (btn) {
                const cod = btn.getAttribute('data-cod');
                const dia = btn.getAttribute('data-dia');
                fetch(`cuotas_api.php?action=delete&cod=${encodeURIComponent(cod)}&dia=${encodeURIComponent(dia)}`)
                    .then(r=>r.text())
                    .then(html => { this.innerHTML = html; })
                    .catch(()=>alert('Error al eliminar cuota'));
            }
        });
    }
});

// Devoluciones: cargar listado
function cargarDevoluciones(){
    const cont = document.getElementById('devoluciones');
    if (!cont) return;
    const fecha = document.getElementById('fecha_dev')?.value || '';
    const codVend = document.getElementById('dev_cod_vend')?.value || '';
    const codCli = document.getElementById('dev_cod_cli')?.value || '';
    const veh = document.getElementById('dev_veh')?.value || '';
    cont.innerHTML = '<p>Cargando...</p>';
    const params = new URLSearchParams();
    if (fecha) params.append('fecha', fecha);
    if (codVend) params.append('cod_vendedor', codVend);
    if (codCli) params.append('cod_cliente', codCli);
    if (veh) params.append('vehiculo', veh);
    fetch('devoluciones_gestion.php?' + params.toString())
        .then(r => r.text())
        .then(html => { cont.innerHTML = html; })
        .catch(() => { cont.innerHTML = '<p>Error al consultar devoluciones.</p>'; });
}

// Cargar lista de vehículos para la fecha y filtros
function cargarVehiculos(){
    const fecha = document.getElementById('fecha_dev')?.value || '';
    const codVend = document.getElementById('dev_cod_vend')?.value || '';
    const codCli = document.getElementById('dev_cod_cli')?.value || '';
    const sel = document.getElementById('dev_veh');
    if (!sel || !fecha) return;
    // placeholder mientras carga
    const currentVal = sel.value;
    sel.innerHTML = '<option value="">Cargando vehículos...</option>';
    const qs = new URLSearchParams();
    qs.append('action','vehiculos');
    qs.append('fecha', fecha);
    if (codVend) qs.append('cod_vendedor', codVend);
    if (codCli) qs.append('cod_cliente', codCli);
    fetch('devoluciones_gestion.php?' + qs.toString())
        .then(r => r.json())
        .then(data => {
            if (!data || data.ok !== true) throw new Error('Respuesta inválida');
            // ordenar: primero pendientes (complete=false), luego completos (complete=true)
            const vehs = Array.isArray(data.vehiculos) ? data.vehiculos.slice() : [];
            vehs.sort((a,b)=>{
                if (!!a.complete !== !!b.complete) return a.complete ? 1 : -1; // false primero
                // Dentro del mismo grupo, ordenar natural por label
                const la = (a.label||'').toString().toLowerCase();
                const lb = (b.label||'').toString().toLowerCase();
                if (la < lb) return -1; if (la > lb) return 1; return 0;
            });
            const opts = ['<option value="">Todos</option>'];
            vehs.forEach(v => {
                const tick = v.complete ? '✓ ' : '';
                const label = tick + v.label + ' (' + v.asignados + '/' + v.total + ')';
                const value = v.value; // puede ser ''
                // para value '', queremos que sea seleccionable; mantenemos value vacío
                opts.push('<option value="' + String(value).replaceAll('"','&quot;') + '">' + label.replaceAll('<','&lt;') + '</option>');
            });
            sel.innerHTML = opts.join('');
            // restaurar selección anterior si existe
            if (currentVal !== undefined && currentVal !== null) {
                sel.value = currentVal;
            }
        })
        .catch(err => {
            sel.innerHTML = '<option value="">Error al cargar vehículos</option>';
            console.error(err);
        });
}

// Listener formulario devoluciones
document.addEventListener('DOMContentLoaded', function(){
    const formDev = document.getElementById('form-devoluciones');
    if (formDev) {
        formDev.addEventListener('submit', function(e){
            e.preventDefault();
            cargarDevoluciones();
        });
        // Actualizar lista de vehículos cuando cambien filtros
        const fechaEl = document.getElementById('fecha_dev');
        const vendEl = document.getElementById('dev_cod_vend');
        const cliEl = document.getElementById('dev_cod_cli');
        if (fechaEl) fechaEl.addEventListener('change', cargarVehiculos);
        if (vendEl) vendEl.addEventListener('input', cargarVehiculos);
        if (cliEl) cliEl.addEventListener('input', cargarVehiculos);
        // cargar al abrir
        setTimeout(cargarVehiculos, 50);
    }
    // Delegación para guardar formularios por camión
    const cont = document.getElementById('devoluciones');
    if (cont) {
        // Cache de opciones (vendedores, clientes, productos) por fecha
        const devOpts = { loadedFor: null, vendedores: [], clientes: [], productos: [], maps: { vend:{}, cli:{}, prod:{} } };
        async function ensureDevOptions(){
            const fecha = document.getElementById('fecha_dev')?.value || '';
            if (!fecha) return devOpts;
            if (devOpts.loadedFor === fecha && devOpts.vendedores.length) return devOpts;
            const url = new URL('devoluciones_gestion.php', location.href);
            url.searchParams.set('action','options');
            if (fecha) url.searchParams.set('fecha', fecha);
            const data = await fetch(url.toString()).then(r=>r.json()).catch(()=>null);
            if (!data || data.ok !== true) return devOpts;
            devOpts.loadedFor = fecha;
            devOpts.vendedores = Array.isArray(data.vendedores) ? data.vendedores : [];
            devOpts.clientes = Array.isArray(data.clientes) ? data.clientes : [];
            devOpts.productos = Array.isArray(data.productos) ? data.productos : [];
            // Mapas por código
            devOpts.maps.vend = Object.fromEntries(devOpts.vendedores.map(v => [String(v.code), v.name||'']));
            devOpts.maps.cli  = Object.fromEntries(devOpts.clientes.map(c => [String(c.code), c.name||'']));
            devOpts.maps.prod = Object.fromEntries(devOpts.productos.map(p => [String(p.code), p.name||'']));
            // Asegurar datalists únicos globales
            buildDatalistsOnce(devOpts);
            return devOpts;
        }
        function buildDatalistsOnce(opts){
            function ensureList(id, items){
                let dl = document.getElementById(id);
                if (!dl) { dl = document.createElement('datalist'); dl.id = id; document.body.appendChild(dl); }
                // Render options
                const html = items.map(it => '<option value="'+String(it.code).replaceAll('"','&quot;')+'">'+(it.name?(' '+String(it.name).replaceAll('<','&lt;')):'')+'</option>').join('');
                dl.innerHTML = html;
            }
            ensureList('dl-vendedores', opts.vendedores);
            ensureList('dl-clientes', opts.clientes);
            ensureList('dl-productos', opts.productos);
        }

        // Reconstruye las filas (sub-líneas) de una devolución en la tabla usando los datos del servidor
        function rebuildDevRows(row, devId, data){
            // Obtener columnas fijas desde la fila actual (5 primeras celdas: vendedor, cliente, nombre, cod_prod, producto)
            const tds = Array.from(row.querySelectorAll('td'));
            const staticCols = tds.slice(0,5).map(td => td.textContent);
            const tbody = row.parentElement;
            if (!tbody) return;
            // Ubicar rango de filas actuales de esa devolución
            const allRows = Array.from(tbody.querySelectorAll('tr'));
            const groupRows = allRows.filter(tr => tr.getAttribute('data-dev-id') === String(devId));
            const insertIndex = groupRows.length ? allRows.indexOf(groupRows[0]) : (allRows.indexOf(row));
            // Eliminar actuales
            groupRows.forEach(tr => tr.remove());
            // Calcular cantidades
            const counts = (data && data.counts) || {};
            const total = (data && data.total != null) ? data.total : 0;
            const ok = counts.OK || 0;
            const sc = counts['Sin compra'] || 0;
            const nla = counts['No llego al almacen'] || 0;
            const na = counts['No autorizado'] || 0;
            const nd = counts['No digitado'] || 0;
            const otros = counts.otros || 0;
            const asignados = ok + sc + nla + na + nd + otros;
            const restantes = Math.max(0, (data && data.restantes != null) ? data.restantes : (total - asignados));

            function rowHtml(cant, etiqueta, isLast, canUndo){
                return '<tr class="dev-row dev-row-class" data-dev-id="'+devId+'">'
                    + '<td>'+staticCols[0]+'</td>'
                    + '<td>'+staticCols[1]+'</td>'
                    + '<td>'+staticCols[2]+'</td>'
                    + '<td>'+staticCols[3]+'</td>'
                    + '<td>'+staticCols[4]+'</td>'
                    + '<td style="text-align:right;">'+cant+'</td>'
                    + '<td>'+etiqueta + (canUndo && isLast ? (' &nbsp; <form class="form-bulk" method="post" action="devoluciones_gestion.php" style="display:inline-block; margin-left:6px;"><input type="hidden" name="action" value="undo_all"><input type="hidden" name="devolucion_id" value="'+devId+'"><button type="submit" data-undo="1" class="btn-undo" style="background:#dc3545;color:#fff;border:none;padding:4px 8px;border-radius:4px;">Deshacer</button></form>') : '') + '</td>'
                    + '</tr>';
            }
            function pendHtml(cant){
                // Select de estados
                const sel = '<select name="estado" '+(cant>0?'':'disabled')+' class="estado-select">'
                    + '<option value="OK">OK</option>'
                    + '<option value="No llego al almacen">No llego al almacen</option>'
                    + '<option value="Sin compra">Sin compra</option>'
                    + '<option value="No autorizado">No autorizado</option>'
                    + '<option value="No digitado">No digitado</option>'
                    + '</select>';
                const form = '<form class="form-bulk" method="post" action="devoluciones_gestion.php" style="margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">'
                    + '<input type="hidden" name="action" value="add_bulk">'
                    + '<input type="hidden" name="devolucion_id" value="'+devId+'">'
                    + '<label>Cant: <input type="number" name="cantidad" min="1" max="'+cant+'" value="'+(cant>0?cant:0)+'" '+(cant>0?'':'disabled')+' style="width:80px; padding:4px;"></label>'
                    + sel
                    + '<button type="submit" '+(cant>0?'':'disabled')+'>Agregar</button>'
                    + '<button type="submit" data-undo="1" class="btn-undo" '+(asignados>0?'':'disabled')+' style="background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:4px;">Deshacer</button>'
                    + '</form>'
                    + '<div class="msg-ajax" style="margin-top:4px;"></div>';
                return '<tr class="dev-row dev-row-pend" data-dev-id="'+devId+'">'
                    + '<td>'+staticCols[0]+'</td>'
                    + '<td>'+staticCols[1]+'</td>'
                    + '<td>'+staticCols[2]+'</td>'
                    + '<td>'+staticCols[3]+'</td>'
                    + '<td>'+staticCols[4]+'</td>'
                    + '<td style="text-align:right;">'+cant+'</td>'
                    + '<td>'+form+'</td>'
                    + '</tr>';
            }

            const fr = document.createDocumentFragment();
            // Orden: OK, Sin compra, No llego..., Otros, luego Pendientes
            const parts = [];
            if (ok > 0) parts.push(['OK', ok]);
            if (sc > 0) parts.push(['Sin compra', sc]);
            if (nla > 0) parts.push(['No llego al almacen', nla]);
            if (na > 0) parts.push(['No autorizado', na]);
            if (nd > 0) parts.push(['No digitado', nd]);
            if (otros > 0) parts.push(['Otros', otros]);
            const canUndoInline = (restantes === 0 && asignados > 0);
            parts.forEach((p, idx) => {
                const isLast = idx === parts.length - 1;
                fr.appendChild(htmlToNode(rowHtml(p[1], p[0], isLast, canUndoInline)));
            });
            // Incluir fila de pendientes/acciones sólo si hay restantes
            if (restantes > 0) fr.appendChild(htmlToNode(pendHtml(restantes)));

            // Insertar en la posición
            const afterNode = (insertIndex >= 0 && insertIndex < allRows.length) ? allRows[insertIndex] : null;
            if (afterNode && afterNode.parentElement === tbody) {
                // Insertar antes del afterNode (que fue quizá removido si pertenecía al grupo); buscar el nodo actual en posición insertIndex
                const ref = tbody.children[insertIndex] || null;
                if (ref) tbody.insertBefore(fr, ref); else tbody.appendChild(fr);
            } else {
                tbody.appendChild(fr);
            }
        }
        function htmlToNode(html){
            const t = document.createElement('template');
            t.innerHTML = html.trim();
            return t.content.firstChild;
        }

        // Click en OK_Restantes por camión: asigna OK a todas las unidades restantes de cada fila del bloque
        cont.addEventListener('click', function(e){
            const btn = e.target.closest('.btn-ok-restantes');
            if (!btn) return;
            const bloque = btn.closest('.bloque-vehiculo');
            if (!bloque) return;
            if (btn.disabled) return;
            btn.disabled = true;
            const origText = btn.textContent;
            btn.textContent = 'Procesando...';
            const forms = Array.from(bloque.querySelectorAll('form.form-bulk'));

            let totalAsignadas = 0;
            let filasAfectadas = 0;

            // Procesar en serie para no saturar
            (async function processAll(){
                for (const form of forms) {
                    const input = form.querySelector('input[name="cantidad"]');
                    const select = form.querySelector('select[name="estado"]');
                    const row = form.closest('tr');
                    const msg = row ? row.querySelector('.msg-ajax') : null;
                    const btnSubmit = form.querySelector('button[type="submit"]');
                    const undoBtn = form.querySelector('button[data-undo="1"]');
                    const restantes = parseInt(input?.max || '0', 10) || 0;
                    if (restantes <= 0) continue;
                    if (msg) { msg.textContent = 'Asignando OK ('+restantes+')...'; msg.className = 'msg-ajax'; }
                    if (select) select.value = 'OK';
                    const fd = new FormData(form);
                    try { fd.set('action', 'add_bulk'); fd.set('estado','OK'); fd.set('cantidad', String(restantes)); } catch(_) {}
                    const data = await fetch('devoluciones_gestion.php', {
                        method: 'POST', body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    }).then(r => {
                        const ct = r.headers.get('content-type') || '';
                        if (ct.indexOf('application/json') !== -1) return r.json();
                        return r.text().then(t => ({ ok: r.ok, message: t }));
                    });
                    if (!data || data.ok === false) { throw new Error((data && data.message) || 'Error'); }
                    // Actualizar UI fila
                    if (row) {
                        rebuildDevRows(row, data.devolucion_id, data);
                        if (msg) { msg.textContent = ''; msg.className = 'msg-ajax'; }
                    }
                    totalAsignadas += data.assigned || restantes;
                    filasAfectadas += 1;
                }
            })().then(()=>{
                btn.textContent = 'Hecho ('+totalAsignadas+')';
                if (typeof cargarVehiculos === 'function') {
                    try { cargarVehiculos(); } catch(_){}
                }
            }).catch(err=>{
                btn.textContent = 'Error';
                alert('Error en OK_Restantes: ' + err.message);
            }).finally(()=>{
                setTimeout(()=>{ btn.textContent = origText; btn.disabled = false; }, 1500);
            });
        });
        // Click en Add+ por camión: agrega una fila editable para insertar manualmente una devolución
        cont.addEventListener('click', async function(e){
            const btnAdd = e.target.closest('.btn-add-line');
            if (!btnAdd) return;
            const bloque = btnAdd.closest('.bloque-vehiculo');
            if (!bloque) return;
            const vehiculo = btnAdd.getAttribute('data-vehiculo') || '';
            // Evitar múltiples filas nuevas por bloque
            if (bloque.querySelector('tr.dev-row-new')) {
                const first = bloque.querySelector('tr.dev-row-new input');
                if (first) first.focus();
                return;
            }
            const opts = await ensureDevOptions();
            const tbody = bloque.querySelector('tbody');
            if (!tbody) return;
            const tr = document.createElement('tr');
            tr.className = 'dev-row dev-row-new';
            tr.setAttribute('data-vehiculo', vehiculo);
            tr.innerHTML = ''
                + '<td><input list="dl-vendedores" class="n-vend" style="width:90px;" placeholder="Cod" /></td>'
                + '<td><input list="dl-clientes" class="n-cli" style="width:110px;" placeholder="Cod" /></td>'
                + '<td><input type="text" class="n-cli-nom" style="min-width:220px;" placeholder="Nombre cliente" /></td>'
                + '<td><input list="dl-productos" class="n-prod" style="width:90px;" placeholder="Cod" /></td>'
                + '<td><input type="text" class="n-prod-nom" style="min-width:260px;" placeholder="Nombre producto" /></td>'
                + '<td style="text-align:right;"><input type="number" class="n-cant" min="1" step="1" value="1" style="width:80px; text-align:right;" /></td>'
                + '<td>'
                + '  <button type="button" class="btn-save-new">Guardar</button>'
                + '  <button type="button" class="btn-cancel-new" style="background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:4px; margin-left:6px;">Cancelar</button>'
                + '</td>';
            tbody.appendChild(tr);
            // Autorellenar nombres al cambiar códigos
            const iVend = tr.querySelector('.n-vend');
            const iCli = tr.querySelector('.n-cli');
            const iCliNom = tr.querySelector('.n-cli-nom');
            const iProd = tr.querySelector('.n-prod');
            const iProdNom = tr.querySelector('.n-prod-nom');
            iVend?.addEventListener('change', ()=>{}); // reservado por si se requiere validar
            iCli?.addEventListener('change', ()=>{ const v=iCli.value.trim(); iCliNom.value = opts.maps.cli[v] || iCliNom.value; });
            iProd?.addEventListener('change', ()=>{ const v=iProd.value.trim(); iProdNom.value = opts.maps.prod[v] || iProdNom.value; });
            (iVend||{}).focus?.();
        });
        // Guardar/Cancelar nueva fila
        cont.addEventListener('click', function(e){
            const btnCancel = e.target.closest('.btn-cancel-new');
            if (btnCancel) {
                const tr = btnCancel.closest('tr.dev-row-new');
                if (tr) tr.remove();
                return;
            }
            const btnSave = e.target.closest('.btn-save-new');
            if (!btnSave) return;
            const tr = btnSave.closest('tr.dev-row-new');
            if (!tr) return;
            const vehiculo = tr.getAttribute('data-vehiculo') || '';
            const fecha = document.getElementById('fecha_dev')?.value || '';
            const codVend = tr.querySelector('.n-vend')?.value.trim() || '';
            const codCli = tr.querySelector('.n-cli')?.value.trim() || '';
            const nomCli = tr.querySelector('.n-cli-nom')?.value.trim() || '';
            const codProd = tr.querySelector('.n-prod')?.value.trim() || '';
            const nomProd = tr.querySelector('.n-prod-nom')?.value.trim() || '';
            const cantidad = parseFloat(tr.querySelector('.n-cant')?.value || '0') || 0;
            if (!fecha || !codVend || !codCli || !codProd || cantidad <= 0) {
                alert('Complete código de vendedor, cliente, producto y cantidad > 0.');
                return;
            }
            btnSave.disabled = true; btnSave.textContent = 'Guardando...';
            const fd = new FormData();
            fd.append('action','create_manual');
            fd.append('fecha', fecha);
            fd.append('vehiculo', vehiculo);
            fd.append('codigovendedor', codVend);
            fd.append('nombrevendedor', '');
            fd.append('codigocliente', codCli);
            fd.append('nombrecliente', nomCli);
            fd.append('codigoproducto', codProd);
            fd.append('nombreproducto', nomProd);
            fd.append('cantidad', String(cantidad));
            fetch('devoluciones_gestion.php', { method:'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data || data.ok !== true) throw new Error((data && data.message) || 'Error');
                    // Recargar listado para reflejar la nueva fila
                    if (typeof cargarDevoluciones === 'function') try { cargarDevoluciones(); } catch(_) {}
                    if (typeof cargarVehiculos === 'function') try { cargarVehiculos(); } catch(_) {}
                })
                .catch(err => {
                    alert('No se pudo guardar: ' + (err.message||''));
                })
                .finally(()=>{
                    const row = btnSave.closest('tr.dev-row-new');
                    if (row) row.remove();
                });
        });
        cont.addEventListener('submit', function(e){
            const formEstados = e.target.closest('form.form-estados');
            const formBulk = e.target.closest('form.form-bulk');
            if (formBulk) {
                e.preventDefault();
                const form = formBulk;
                const row = form.closest('tr');
                const msg = row ? row.querySelector('.msg-ajax') : null;
                const btn = form.querySelector('button[type="submit"]');
                const submitter = e.submitter || document.activeElement;
                const isUndo = submitter && submitter.getAttribute && submitter.getAttribute('data-undo') === '1';
                if (btn) btn.disabled = true;
                // No mostrar mensajes emergentes
                if (msg) { msg.textContent = ''; msg.className = 'msg-ajax'; }
                const fd = new FormData(form);
                try { fd.set('action', isUndo ? 'undo_all' : 'add_bulk'); } catch(e) {}
                fetch('devoluciones_gestion.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(r => {
                    const ct = r.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') !== -1) return r.json();
                    return r.text().then(t => ({ ok: r.ok, message: t }));
                })
                .then(data => {
                    if (!data || data.ok === false) {
                        throw new Error((data && data.message) || 'Error desconocido');
                    }
                    if (row) {
                        rebuildDevRows(row, data.devolucion_id, data);
                    }
                    // Actualizar lista de vehículos (check) sin recargar
                    if (typeof cargarVehiculos === 'function') {
                        try { cargarVehiculos(); } catch(_){}
                    }
                })
                .catch(err => {
                    if (msg) { msg.textContent = ''; msg.className = 'msg-ajax'; }
                })
                .finally(() => {
                    const qtyInput = form.querySelector('input[name="cantidad"]');
                    if (btn && (!qtyInput || !qtyInput.disabled)) btn.disabled = false;
                });
                return; // no continuar al manejo de form-estados
            }
            if (formEstados) {
                e.preventDefault();
                const form = formEstados;
                const fd = new FormData(form);
                fetch('devoluciones_gestion.php', { method:'POST', body: fd })
                    .then(r => r.text())
                    .then(msg => {
                        const box = document.createElement('div');
                        box.innerHTML = msg;
                        form.insertAdjacentElement('beforebegin', box.firstChild);
                        // Refrescar listado tras guardar el formulario grande
                        setTimeout(() => cargarDevoluciones(), 300);
                    })
                    .catch(()=>alert('Error al guardar estados'));
            }
        });
    }
});

// Recojos: cargar listado
function cargarRecojos(){
    const cont = document.getElementById('recojos');
    if (!cont) return;
    const fechaSingle = document.getElementById('fecha_recojo')?.value || '';
    const fechaDesde = document.getElementById('fecha_recojo_desde')?.value || '';
    const fechaHasta = document.getElementById('fecha_recojo_hasta')?.value || '';
    const vd = document.getElementById('vd_recojo')?.value || '';
    const cliente = document.getElementById('cliente_recojo')?.value || '';
    const supervisor = document.getElementById('supervisor_recojo')?.value || '';
    const pend = document.getElementById('recojo_pend')?.checked || false;
    cont.innerHTML = '<p>Cargando...</p>';
    const params = new URLSearchParams();
    if (fechaSingle) {
        params.append('fecha', fechaSingle);
    } else {
        if (fechaDesde) params.append('fecha_desde', fechaDesde);
        if (fechaHasta) params.append('fecha_hasta', fechaHasta);
    }
    if (vd) params.append('cod_vendedor', vd);
    if (cliente) params.append('cliente', cliente);
    if (supervisor) params.append('supervisor', supervisor);
    if (pend) params.append('pendientes', '1');
    fetch('recojos_consulta.php?' + params.toString())
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => { cont.innerHTML = html; })
        .catch((err) => { cont.innerHTML = '<p>Error al consultar recojos: ' + (err.message||'') + '</p>'; });
}

// Listener formulario recojos
document.addEventListener('DOMContentLoaded', function(){
    const formR = document.getElementById('form-recojos');
    if (formR) {
        formR.addEventListener('submit', function(e){
            e.preventDefault();
            cargarRecojos();
        });
        const pend = document.getElementById('recojo_pend');
        if (pend) pend.addEventListener('change', function(){
            // Si ya hay un vendedor ingresado y fecha, refresca automáticamente
            const vd = document.getElementById('vd_recojo')?.value || '';
            const fecha = document.getElementById('fecha_recojo')?.value || '';
            if (vd && fecha) cargarRecojos();
        });
    }
});

// Cobranzas: resumen por vendedor
function cargarCobranzas(){
    const cont = document.getElementById('cobranzas');
    if (!cont) return;
    const vd = document.getElementById('vd_cobranza')?.value || '';
    cont.innerHTML = '<p>Cargando...</p>';
    const qs = new URLSearchParams();
    if (vd) qs.append('cod_vendedor', vd);
    fetch('cobranzas_resumen.php' + (qs.toString() ? ('?' + qs.toString()) : ''))
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => { cont.innerHTML = html; })
        .catch(err => { cont.innerHTML = '<p>Error al consultar cobranzas: ' + (err.message||'') + '</p>'; });
}

document.addEventListener('DOMContentLoaded', function(){
    const formC = document.getElementById('form-cobranzas');
    if (formC) {
        formC.addEventListener('submit', function(e){ e.preventDefault(); cargarCobranzas(); });
    }
});
// Consulta AJAX para resumen de pedidos por fecha
const formResumen = document.getElementById('form-resumen');
if (formResumen) {
    formResumen.addEventListener('submit', function(e) {
        e.preventDefault();
        const fecha = document.getElementById('fecha_resumen').value;
        const resumen = document.getElementById('resumen');
        resumen.innerHTML = '<p>Cargando...</p>';
        fetch('resumen_pedidos.php?fecha=' + encodeURIComponent(fecha))
            .then(res => res.text())
            .then(html => {
                resumen.innerHTML = html;
            })
            .catch(() => {
                resumen.innerHTML = '<p>Error al consultar el resumen.</p>';
            });
    });
}
// Vista previa del archivo seleccionado (opcional, solo nombre)
const fileInput = document.querySelector('input[type="file"]');
if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const preview = document.getElementById('preview');
        if (this.files.length > 0) {
            preview.innerHTML = `<p>Archivo seleccionado: <strong>${this.files[0].name}</strong></p>`;
        } else {
            preview.innerHTML = '';
        }
    });
}

// Consulta AJAX para pedidos por vendedor
const formConsultar = document.getElementById('form-consultar');
if (formConsultar) {
    formConsultar.addEventListener('submit', function(e) {
        e.preventDefault();
        const codVendedor = document.getElementById('cod_vendedor').value;
        const resultados = document.getElementById('resultados');
        resultados.innerHTML = '<p>Cargando...</p>';
        fetch('consultar_pedidos.php?cod_vendedor=' + encodeURIComponent(codVendedor))
            .then(res => res.text())
            .then(html => {
                resultados.innerHTML = html;
            })
            .catch(() => {
                resultados.innerHTML = '<p>Error al consultar los pedidos.</p>';
            });
    });
}

// Admin: cargar lista de cuotas
function cargarCuotas(){
    const cont = document.getElementById('lista-cuotas');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando cuotas...</p>';
    fetch('cuotas_api.php?action=list')
        .then(r => r.text())
        .then(html => { cont.innerHTML = html; })
        .catch(()=>{ cont.innerHTML = '<p>Error al cargar cuotas</p>'; });
}