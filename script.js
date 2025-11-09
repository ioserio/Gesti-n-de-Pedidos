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
    // Validar permisos por módulo si están disponibles
    try {
        const map = {
            'subir':'importar',
            'consultar':'consultar',
            'resumen':'resumen',
            'cobranzas':'cobranzas',
            'devoluciones':'devoluciones',
            'recojos':'recojos',
            'admin':'admin',
            'rutas':'rutas',
            'ctacte_vendedor':'ctacte_vendedor',
            'usuarios':'usuarios',
            'permisos':'permisos',
            'sesiones':'usuarios',
            'inicio': null
        };
        if (modulo && map.hasOwnProperty(modulo) && map[modulo]) {
            if (window.__PERMS && window.__PERMS[ map[modulo] ] === false) {
                // Módulo bloqueado: mostrar Inicio y salir
                const inicio = document.getElementById('modulo-inicio');
                if (inicio) inicio.style.display = 'block';
                const ids = ['modulo-subir','modulo-consultar','modulo-resumen','modulo-cobranzas','modulo-admin','modulo-rutas','modulo-usuarios','modulo-permisos','modulo-devoluciones','modulo-recojos','modulo-sesiones'];
                ids.forEach(id => { const el = document.getElementById(id); if (el) el.style.display='none'; });
                return;
            }
        }
    } catch(_){}
    const inicio = document.getElementById('modulo-inicio');
    if (inicio) inicio.style.display = (modulo === 'inicio') ? 'block' : 'none';
    document.getElementById('modulo-subir').style.display = (modulo === 'subir') ? 'block' : 'none';
    document.getElementById('modulo-consultar').style.display = (modulo === 'consultar') ? 'block' : 'none';
    document.getElementById('modulo-resumen').style.display = (modulo === 'resumen') ? 'block' : 'none';
    const cobr = document.getElementById('modulo-cobranzas');
    if (cobr) cobr.style.display = (modulo === 'cobranzas') ? 'block' : 'none';
    // Alternar modo ancho completo para módulos que requieren ocupar todo el ancho (cobranzas y devoluciones)
    const wrap = document.querySelector('.container');
    if (wrap) {
        if (modulo === 'cobranzas' || modulo === 'devoluciones') wrap.classList.add('fullwidth');
        else wrap.classList.remove('fullwidth');
    }
    const admin = document.getElementById('modulo-admin');
    if (admin) admin.style.display = (modulo === 'admin') ? 'block' : 'none';
        const rutas = document.getElementById('modulo-rutas');
        if (rutas) rutas.style.display = (modulo === 'rutas') ? 'block' : 'none';
        const vsmod = document.getElementById('modulo-ctacte-vendedor');
        if (vsmod) vsmod.style.display = (modulo === 'ctacte_vendedor') ? 'block' : 'none';
        const usuarios = document.getElementById('modulo-usuarios');
        if (usuarios) usuarios.style.display = (modulo === 'usuarios') ? 'block' : 'none';
        const permisos = document.getElementById('modulo-permisos');
        if (permisos) permisos.style.display = (modulo === 'permisos') ? 'block' : 'none';
        const sesiones = document.getElementById('modulo-sesiones');
        if (sesiones) sesiones.style.display = (modulo === 'sesiones') ? 'block' : 'none';
        // Detener auto-refresh de sesiones al salir del módulo
        if (modulo !== 'sesiones' && window.__SESSIONS_TIMER) {
            clearInterval(window.__SESSIONS_TIMER);
            window.__SESSIONS_TIMER = null;
        }
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
        } else if (modulo === 'rutas') {
            setTimeout(function(){ cargarRutas(); }, 50);
        } else if (modulo === 'ctacte_vendedor') {
            setTimeout(function(){ cargarVS(); }, 50);
        } else if (modulo === 'permisos') {
            setTimeout(function(){ cargarPermisos(); }, 50);
        } else if (modulo === 'usuarios') {
            setTimeout(function(){ cargarUsuarios(); }, 50);
        } else if (modulo === 'sesiones') {
            setTimeout(function(){ cargarSesiones(); }, 50);
            if (window.__SESSIONS_TIMER) { clearInterval(window.__SESSIONS_TIMER); }
            window.__SESSIONS_TIMER = setInterval(function(){
                const ses = document.getElementById('modulo-sesiones');
                if (ses && ses.style.display !== 'none') cargarSesiones();
            }, 30000);
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
    // Mostrar por defecto una pantalla de inicio (no cargar ningún módulo protegido)
    window.mostrarModulo('inicio');
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
        .then(html => { 
            cont.innerHTML = html; 
            try { enhanceDevolucionesTables(); } catch(_){ }
        })
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
        async function ensureDevOptions(forceReload=false){
            const fecha = document.getElementById('fecha_dev')?.value || '';
            if (!fecha) return devOpts;
            if (devOpts.loadedFor === fecha && devOpts.vendedores.length && !forceReload) return devOpts;
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

        // Marca encabezados y agrega capacidades de ordenamiento por columna
        function enhanceDevolucionesTables(){
            const tables = cont.querySelectorAll('.bloque-vehiculo table');
            tables.forEach(tbl => {
                const ths = tbl.querySelectorAll('thead th');
                ths.forEach((th, idx) => {
                    // Por ahora, solo hacemos visualmente destacable la columna Nombre Cliente (índice 2)
                    if (idx === 2) th.classList.add('sortable');
                });
            });
        }

        // Ordena una tabla por columna manteniendo unidas las filas del mismo dev-id
        function sortTableByColumn(table, colIndex, direction){
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const allRows = Array.from(tbody.querySelectorAll('tr.dev-row'));
            // Agrupar filas por devolucion_id
            const groupsMap = new Map();
            allRows.forEach(tr => {
                const id = tr.getAttribute('data-dev-id') || ('__nogroup__' + Math.random());
                if (!groupsMap.has(id)) groupsMap.set(id, []);
                groupsMap.get(id).push(tr);
            });
            const groups = Array.from(groupsMap.entries()).map(([id, rows]) => {
                // Clave de orden: texto de la columna en la primera fila del grupo
                const first = rows[0];
                const tds = first.querySelectorAll('td');
                let key = '';
                if (tds && tds[colIndex]) key = (tds[colIndex].textContent || '').trim();
                return { id, rows, key };
            });
            const dir = (direction === 'desc') ? -1 : 1;
            groups.sort((a,b) => a.key.toLowerCase().localeCompare(b.key.toLowerCase(), 'es') * dir);
            // Reinsertar en el nuevo orden
            const frag = document.createDocumentFragment();
            groups.forEach(g => g.rows.forEach(tr => frag.appendChild(tr)));
            tbody.innerHTML = '';
            tbody.appendChild(frag);
        }

        // Delegación para clicks en encabezados (thead th)
        cont.addEventListener('click', function(e){
            const th = e.target.closest('th');
            if (!th) return;
            const table = th.closest('table');
            if (!table || !table.closest('.bloque-vehiculo')) return;
            // Índice de la columna
            const thRow = th.parentElement;
            const colIndex = Array.prototype.indexOf.call(thRow.children, th);
            // Solo ordenamos por "Nombre Cliente" (índice 2)
            if (colIndex !== 2) return;
            // Alternar dirección
            const current = th.getAttribute('data-sort-dir') || 'none';
            const next = current === 'asc' ? 'desc' : 'asc';
            // Limpiar indicadores en otros th
            const allTh = table.querySelectorAll('thead th');
            allTh.forEach(x => x.removeAttribute('data-sort-dir'));
            th.setAttribute('data-sort-dir', next);
            sortTableByColumn(table, colIndex, next);
        });

        // Click en OK_Restantes por camión: asigna los restantes de cada fila usando su estado, en lote (rápido)
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
            // Construir un payload con todas las filas y su estado seleccionado
            const items = [];
            forms.forEach(form => {
                const input = form.querySelector('input[name="cantidad"]');
                const restantes = parseInt(input?.max || '0', 10) || 0;
                if (restantes <= 0) return;
                const devId = parseInt(form.querySelector('input[name="devolucion_id"]')?.value || '0', 10) || 0;
                const estadoSel = form.querySelector('select[name="estado"]');
                const estado = (estadoSel && estadoSel.value) ? estadoSel.value : 'OK';
                if (devId > 0) items.push({ devolucion_id: devId, estado });
            });
            if (items.length === 0) {
                btn.textContent = origText;
                btn.disabled = false;
                return;
            }
            fetch('devoluciones_gestion.php?action=bulk_restantes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({ items })
            }).then(r => r.json()).then(data => {
                if (!data || data.ok !== true) throw new Error((data && data.message) || 'Error');
                // Actualizar cada fila afectada con el resultado
                const resultados = Array.isArray(data.resultados) ? data.resultados : [];
                resultados.forEach(res => {
                    const devId = String(res.devolucion_id);
                    const row = bloque.querySelector('tr.dev-row[data-dev-id="' + devId + '"]');
                    if (row) rebuildDevRows(row, res.devolucion_id, res);
                });
                btn.textContent = 'Hecho ('+resultados.length+')';
                if (typeof cargarVehiculos === 'function') {
                    try { cargarVehiculos(); } catch(_){ }
                }
            }).catch(err => {
                btn.textContent = 'Error';
                alert('Error en OK_Restantes: ' + (err.message||''));
            }).finally(() => {
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
            addNewRowForBlock(bloque, vehiculo, opts);
        });
    function addNewRowForBlock(bloque, vehiculo, opts, prefill){
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
            iVend?.addEventListener('change', ()=>{});
            iCli?.addEventListener('change', ()=>{ const v=iCli.value.trim(); iCliNom.value = opts.maps.cli[v] || iCliNom.value; });
            iProd?.addEventListener('change', ()=>{ const v=iProd.value.trim(); iProdNom.value = opts.maps.prod[v] || iProdNom.value; });
            // Prefill si viene provisto (último usado)
            if (prefill) {
                if (iVend && prefill.vendCode) iVend.value = prefill.vendCode;
                if (iCli && prefill.cliCode) iCli.value = prefill.cliCode;
                if (iCliNom) iCliNom.value = prefill.cliName || (prefill.cliCode ? (opts.maps.cli[prefill.cliCode] || '') : '');
                // No prefill de producto: dejar en blanco a propósito
                const qty = tr.querySelector('.n-cant');
                if (qty && prefill.qty) qty.value = String(prefill.qty);
            }
            (iVend||{}).focus?.();
        }
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
                .then(async data => {
                    if (!data || data.ok !== true) throw new Error((data && data.message) || 'Error');
                    // Refrescar solo el bloque del camión afectado y dejar preparada otra línea nueva
                    await reloadVehiculoBlock(vehiculo);
                    if (typeof cargarVehiculos === 'function') try { cargarVehiculos(); } catch(_) {}
                    try {
                        // Fuerza refresco de opciones para que las listas incluyan el nuevo cliente/producto/vendedor
                        const opts = await ensureDevOptions(true);
                        const blocks = Array.from(cont.querySelectorAll('.bloque-vehiculo'));
                        const bloque2 = blocks.find(b => {
                            const btn = b.querySelector('.btn-add-line');
                            return btn && (btn.getAttribute('data-vehiculo') || '') === vehiculo;
                        });
                        if (bloque2) addNewRowForBlock(bloque2, vehiculo, opts, {
                            vendCode: codVend,
                            cliCode: codCli,
                            cliName: nomCli,
                            qty: 1
                        });
                    } catch(_) {}
                })
                .catch(err => {
                    alert('No se pudo guardar: ' + (err.message||''));
                })
                .finally(()=>{
                    const row = btnSave.closest('tr.dev-row-new');
                    if (row) row.remove();
                });
        });
        // Refresca solo el bloque de un vehículo específico sin recargar toda la vista
        function reloadVehiculoBlock(vehiculoLabel){
            return new Promise((resolve) => {
            const fecha = document.getElementById('fecha_dev')?.value || '';
            const codVend = document.getElementById('dev_cod_vend')?.value || '';
            const codCli = document.getElementById('dev_cod_cli')?.value || '';
            const qs = new URLSearchParams();
            if (fecha) qs.append('fecha', fecha);
            if (codVend) qs.append('cod_vendedor', codVend);
            if (codCli) qs.append('cod_cliente', codCli);
            // Para el caso SIN VEHICULO, el valor real es cadena vacía
            if (vehiculoLabel && vehiculoLabel !== 'SIN VEHICULO') {
                qs.append('vehiculo', vehiculoLabel);
            } else {
                // Forzar filtro a vacío para traer solo ese bloque especial
                qs.append('vehiculo', '');
            }
            fetch('devoluciones_gestion.php?' + qs.toString())
                .then(r => r.text())
                .then(html => {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    const newBlock = tmp.querySelector('.bloque-vehiculo');
                    if (!newBlock) { resolve(); return; }
                    const blocks = Array.from(cont.querySelectorAll('.bloque-vehiculo'));
                    const oldBlock = blocks.find(b => {
                        const btn = b.querySelector('.btn-add-line');
                        return btn && (btn.getAttribute('data-vehiculo') || '') === vehiculoLabel;
                    });
                    if (oldBlock) {
                        oldBlock.replaceWith(newBlock);
                    } else if (blocks.length) {
                        // Si no existe (nuevo camión en la vista), lo insertamos al final
                        cont.appendChild(newBlock);
                    } else {
                        // Si no hay bloques, reemplazar todo
                        cont.innerHTML = '';
                        cont.appendChild(newBlock);
                    }
                    resolve();
                })
                .catch(()=>{ resolve(); });
            });
        }
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
    const vd = document.getElementById('vd_cobranza_sel')?.value || '';
    const supSel = document.getElementById('sup_cobranza');
    const supNum = supSel ? (supSel.value || '') : '';
    let supName = supSel ? (supSel.options[supSel.selectedIndex]?.text || '') : '';
    if (supName && supName.indexOf(' - ') !== -1) {
        // Quedarnos solo con el nombre del supervisor (después del número)
        supName = supName.split(' - ').slice(1).join(' - ').trim();
    }
    cont.innerHTML = '<p>Cargando...</p>';
    const qs = new URLSearchParams();
    if (vd) qs.append('cod_vendedor', vd);
    if (supNum && supName) qs.append('supervisor', supName);
    fetch('cobranzas_resumen.php' + (qs.toString() ? ('?' + qs.toString()) : ''))
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => { cont.innerHTML = html; })
        .catch(err => { cont.innerHTML = '<p>Error al consultar cobranzas: ' + (err.message||'') + '</p>'; });
}

document.addEventListener('DOMContentLoaded', function(){
    // Chequeo ligero de sesión en cliente; si no hay sesión, redirige a login
    fetch('auth_check.php', { cache: 'no-store' })
        .then(r => r.json()).then(info => {
            if (!info || info.authenticated !== true) {
                const next = encodeURIComponent(location.pathname + location.search);
                location.href = 'login.php?next=' + next;
                return;
            }
            // Pintar el usuario en el header
            const tgt = document.getElementById('user-name');
            if (tgt) {
                const nom = (info.nombre && info.nombre.trim()) ? info.nombre.trim() : (info.usuario || '');
                tgt.textContent = nom ? `Hola, ${nom}` : '';
            }
            // Guardar id de usuario actual globalmente
            window.__CURRENT_USER_ID = info.id || 0;
            // Iniciar heartbeat (ping cada 60s) para mantener last_seen actualizado
            try {
                if (!window.__HEARTBEAT && window.__CURRENT_USER_ID) {
                    const doPing = () => fetch('users_api.php?action=heartbeat', { method:'POST' }).catch(()=>{});
                    doPing();
                    window.__HEARTBEAT = setInterval(doPing, 60000);
                }
            } catch(_) {}
            // Cargar permisos del usuario y ocultar menús no permitidos
            try {
                fetch('permisos_api.php?action=my', { cache:'no-store' })
                    .then(r => r.json())
                    .then(p => {
                        if (!p || p.ok !== true) return;
                        const perms = p.permisos || {};
                        // Guardar globalmente para validar accesos cuando se invoca mostrarModulo
                        window.__PERMS = perms;
                        applyPermissionStyles(perms);
                    })
                    .catch(()=>{});
            } catch(_){}
        }).catch(() => {});

    const formC = document.getElementById('form-cobranzas');
    if (formC) {
        formC.addEventListener('submit', function(e){ e.preventDefault(); cargarCobranzas(); });
        // Cargar opciones de supervisores y vendedores
        initCobranzasSelectors();
        const supSel = document.getElementById('sup_cobranza');
        if (supSel) supSel.addEventListener('change', function(){ updateVendedoresForSupervisor(); });
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

// Rutas: cargar listado
function cargarRutas(){
    const cont = document.getElementById('lista-rutas');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando rutas...</p>';
    fetch('rutas_api.php?action=list')
        .then(r => r.text())
        .then(html => { cont.innerHTML = html; })
        .catch(()=>{ cont.innerHTML = '<p>Error al cargar rutas</p>'; });
}

// Admin: sesiones (usuarios en línea)
function cargarSesiones(){
    const cont = document.getElementById('lista-sesiones');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando sesiones...</p>';
    const url = new URL('users_api.php', location.href);
    url.searchParams.set('action','sessions');
    fetch(url.toString())
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => {
            cont.innerHTML = html;
            const btn = document.getElementById('btn-refresh-sessions');
            if (btn) btn.addEventListener('click', () => cargarSesiones());
        })
        .catch(err => { cont.innerHTML = '<p>Error al cargar sesiones: ' + (err.message||'') + '</p>'; });
}

// Cobranzas: cargar supervisores y vendedores
function initCobranzasSelectors(){
    const supSel = document.getElementById('sup_cobranza');
    const vdSel = document.getElementById('vd_cobranza_sel');
    if (!supSel || !vdSel) return;
    supSel.innerHTML = '<option value="">Seleccione supervisor...</option>';
    vdSel.innerHTML = '<option value="">Seleccione vendedor...</option>';
    fetch('vendedor_supervisor_api.php?action=options')
        .then(r => r.json())
        .then(data => {
            const sups = (data && Array.isArray(data.supervisores)) ? data.supervisores : [];
            const opts = ['<option value="">Todos</option>'];
            sups.forEach(s => {
                const label = (s.numero + ' - ' + (s.nombre||'')).replaceAll('<','&lt;');
                opts.push('<option value="'+String(s.numero)+'">'+label+'</option>');
            });
            supSel.innerHTML = opts.join('');
        })
        .catch(()=>{});
}

function updateVendedoresForSupervisor(){
    const supSel = document.getElementById('sup_cobranza');
    const vdSel = document.getElementById('vd_cobranza_sel');
    if (!supSel || !vdSel) return;
    const sup = supSel.value || '';
    vdSel.innerHTML = '<option value="">Cargando vendedores...</option>';
    const url = new URL('vendedor_supervisor_api.php', location.href);
    url.searchParams.set('action','options');
    if (sup) url.searchParams.set('numero_supervisor', sup);
    fetch(url.toString())
        .then(r => r.json())
        .then(data => {
            const vends = (data && Array.isArray(data.vendedores)) ? data.vendedores : [];
            const opts = ['<option value="">Todos</option>'];
            vends.forEach(v => {
                const label = (v.codigo + ' - ' + (v.nombre||'')).replaceAll('<','&lt;');
                opts.push('<option value="'+String(v.codigo)+'">'+label+'</option>');
            });
            vdSel.innerHTML = opts.join('');
        })
        .catch(()=>{ vdSel.innerHTML = '<option value="">Seleccione vendedor...</option>'; });
}

// CTACTE/Vendedor: cargar listado
function cargarVS(){
    const cont = document.getElementById('vs-lista');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando...</p>';
    fetch('vendedor_supervisor_api.php?action=list')
        .then(r => r.text())
        .then(html => { cont.innerHTML = html; })
        .catch(()=>{ cont.innerHTML = '<p>Error al cargar la lista.</p>'; });
}

// Delegación de eventos: guardar al cambiar el supervisor o quitar
document.addEventListener('change', function(e){
    const sel = e.target.closest && e.target.closest('select.sup-select');
    if (!sel) return;
    const cod = sel.getAttribute('data-cod') || '';
    const sup = sel.value || '';
    const fd = new FormData();
    fd.append('action','save'); fd.append('codigo_vendedor', cod); fd.append('numero_supervisor', sup);
    sel.disabled = true;
    fetch('vendedor_supervisor_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => { /* feedback sutil */ })
        .catch(()=>{ alert('No se pudo guardar'); })
        .finally(()=>{ sel.disabled = false; });
});

document.addEventListener('click', function(e){
    const btn = e.target.closest && e.target.closest('button.vs-clear');
    if (!btn) return;
    const cod = btn.getAttribute('data-cod') || '';
    const fd = new FormData(); fd.append('action','save'); fd.append('codigo_vendedor', cod); // sin supervisor -> borrar
    btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Quitando...';
    fetch('vendedor_supervisor_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => { cargarVS(); })
        .catch(()=>{ alert('No se pudo quitar'); })
        .finally(()=>{ setTimeout(()=>{ btn.textContent = orig; btn.disabled = false; }, 800); });
});

// Permisos: listado y toggle
function cargarPermisos(){
    const cont = document.getElementById('perm-content');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando permisos...</p>';
    fetch('permisos_api.php?action=list')
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => {
            cont.innerHTML = html;
        })
        .catch(err => { cont.innerHTML = '<p>Error al cargar permisos: ' + (err.message||'') + '</p>'; });
}

// Delegación de cambios de check en permisos
document.addEventListener('click', function(e){
    const chk = e.target.closest('input.perm-toggle[type="checkbox"]');
    if (!chk) return;
    const uid = parseInt(chk.getAttribute('data-uid')||'0', 10) || 0;
    const mod = chk.getAttribute('data-mod')||'';
    const val = chk.checked ? 1 : 0;
    const fd = new FormData();
    fd.append('action','toggle'); fd.append('user_id', String(uid)); fd.append('modulo', mod); fd.append('value', String(val));
    fetch('permisos_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res || res.ok !== true) throw new Error(res && res.error ? res.error : 'Error');
            // Si editamos permisos del usuario actual, reflejar en menú al instante
            try {
                const currentId = window.__CURRENT_USER_ID || 0;
                if (currentId && currentId === uid) {
                    const anchors = document.querySelectorAll('a[data-mod="'+mod+'"]');
                    anchors.forEach(a => {
                        const allowed = !!val;
                        a.classList.toggle('is-enabled', allowed);
                        a.classList.toggle('is-disabled', !allowed);
                        if (!allowed) { a.setAttribute('aria-disabled','true'); a.title='Sin permiso'; }
                        else { a.removeAttribute('aria-disabled'); a.removeAttribute('title'); }
                    });
                    // Actualizar cache de permisos
                    if (!window.__PERMS) window.__PERMS = {};
                    window.__PERMS[mod] = !!val;
                    // Recalcular padres
                    recomputeParentDropdownStates();
                }
            } catch(_){}
            // feedback sutil
            const msg = document.createElement('span');
            msg.textContent = ' Guardado';
            msg.style.marginLeft = '6px'; msg.style.color = '#198754'; msg.style.fontSize = '12px';
            chk.insertAdjacentElement('afterend', msg);
            setTimeout(()=>{ msg.remove(); }, 1200);
        })
        .catch(err => {
            alert('No se pudo guardar el permiso: ' + (err.message||''));
            // revertir
            chk.checked = !val;
        });
});

// Guardado masivo de permisos por fila
document.addEventListener('click', function(e){
    const btn = e.target.closest('button.perm-bulk-save');
    if (!btn) return;
    const uid = parseInt(btn.getAttribute('data-uid')||'0', 10) || 0;
    const tr = btn.closest('tr');
    if (!tr) return;
    // Recolectar estados de todos los checkboxes en la fila
    const mods = {};
    tr.querySelectorAll('input.perm-toggle[type="checkbox"]').forEach(ch => {
        const m = ch.getAttribute('data-mod')||'';
        if (m) mods[m] = ch.checked ? 1 : 0;
    });
    btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Guardando...';
    const fd = new FormData();
    fd.append('action','bulk'); fd.append('user_id', String(uid)); fd.append('mods', JSON.stringify(mods));
    fetch('permisos_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res || res.ok !== true) throw new Error(res && res.error ? res.error : 'Error');
            btn.textContent = 'Guardado';
            // Si se guardaron permisos del usuario actual, actualizar el menú completo
            try {
                const currentId = window.__CURRENT_USER_ID || 0;
                if (currentId && currentId === uid) {
                    document.querySelectorAll('a[data-mod]').forEach(a => {
                        const modKey = a.getAttribute('data-mod');
                        const allowed = (mods.hasOwnProperty(modKey)) ? !!mods[modKey] : a.classList.contains('is-enabled');
                        a.classList.toggle('is-enabled', allowed);
                        a.classList.toggle('is-disabled', !allowed);
                        if (!allowed) { a.setAttribute('aria-disabled','true'); a.title='Sin permiso'; }
                        else { a.removeAttribute('aria-disabled'); a.removeAttribute('title'); }
                    });
                    // Actualizar cache global
                    if (!window.__PERMS) window.__PERMS = {};
                    Object.keys(mods).forEach(k => { window.__PERMS[k] = !!mods[k]; });
                    // Recalcular padres
                    recomputeParentDropdownStates();
                }
            } catch(_){}
        })
        .catch(err => {
            alert('No se pudo guardar: ' + (err.message||''));
        })
        .finally(() => {
            setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1200);
        });
});

// Toggle de submenús por click (además del hover)
document.addEventListener('DOMContentLoaded', function(){
    const nav = document.querySelector('nav');
    if (!nav) return;
    const items = Array.from(nav.querySelectorAll('li.has-submenu > a.submenu-toggle'));
    items.forEach(a => {
        a.addEventListener('click', function(e){
            e.preventDefault();
            const li = a.parentElement;
            const isOpen = li.classList.contains('open');
            // Cerrar otros
            nav.querySelectorAll('li.has-submenu.open').forEach(el => el.classList.remove('open'));
            // Alternar este
            li.classList.toggle('open', !isOpen);
            a.setAttribute('aria-expanded', String(!isOpen));
        });
    });
    // Cerrar al hacer click fuera
    document.addEventListener('click', function(e){
        if (!nav.contains(e.target)) {
            nav.querySelectorAll('li.has-submenu.open').forEach(el => {
                const toggle = el.querySelector('a.submenu-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
                el.classList.remove('open');
            });
        }
    });
    // Cerrar con ESC
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            nav.querySelectorAll('li.has-submenu.open').forEach(el => {
                const toggle = el.querySelector('a.submenu-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
                el.classList.remove('open');
            });
        }
    });
});

// Navegar a Inicio al hacer click en el logo (sin recargar)
document.addEventListener('DOMContentLoaded', function(){
    const brand = document.querySelector('nav a.brand-logo');
    if (!brand) return;
    brand.addEventListener('click', function(e){
        e.preventDefault();
        try { window.mostrarModulo('inicio'); } catch(_){ location.href = 'index.php'; }
    });
});

// Bloquear navegación a módulos sin permiso
document.addEventListener('click', function(e){
    const a = e.target.closest && e.target.closest('a[data-mod].is-disabled');
    if (!a) return;
    e.preventDefault();
    e.stopPropagation();
});

// También bloquear apertura de submenús cuando el padre está deshabilitado
document.addEventListener('click', function(e){
    const a = e.target.closest && e.target.closest('li.has-submenu.disabled > a');
    if (!a) return;
    e.preventDefault();
    e.stopPropagation();
});

function applyPermissionStyles(perms){
    // Marcar cada anchor de módulo
    document.querySelectorAll('a[data-mod]').forEach(a => {
        const mod = a.getAttribute('data-mod');
        const allowed = !!perms[mod];
        a.classList.toggle('is-enabled', allowed);
        a.classList.toggle('is-disabled', !allowed);
        if (!allowed) { a.setAttribute('aria-disabled','true'); a.title='Sin permiso'; }
        else { a.removeAttribute('aria-disabled'); a.removeAttribute('title'); }
    });
    // Recalcular estado de los padres con submenú
    recomputeParentDropdownStates();
}

function recomputeParentDropdownStates(){
    document.querySelectorAll('li.has-submenu').forEach(li => {
        const parentA = li.querySelector(':scope > a');
        const items = li.querySelectorAll(':scope .submenu a[data-mod]');
        if (!items || !items.length) return;
        let anyAllowed = false;
        items.forEach(a => { if (!a.classList.contains('is-disabled')) anyAllowed = true; });
        li.classList.toggle('disabled', !anyAllowed);
        if (parentA) {
            parentA.classList.toggle('is-disabled', !anyAllowed);
            parentA.classList.toggle('is-enabled', anyAllowed);
            if (!anyAllowed) { parentA.setAttribute('aria-disabled','true'); parentA.title='Sin permiso'; }
            else { parentA.removeAttribute('aria-disabled'); parentA.removeAttribute('title'); }
        }
    });
}

// Usuarios: listar/crear/editar
function cargarUsuarios(){
    const cont = document.getElementById('lista-usuarios');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando usuarios...</p>';
    fetch('users_api.php?action=list')
        .then(r => {
            if (!r.ok) return r.text().then(t=>{ throw new Error(t||('HTTP '+r.status)); });
            return r.text();
        })
        .then(html => { cont.innerHTML = html; })
        .catch(err => { cont.innerHTML = '<p>Error al cargar usuarios: ' + (err.message||'') + '</p>'; });
}

document.addEventListener('DOMContentLoaded', function(){
    const formNew = document.getElementById('form-usuario-new');
    if (formNew) {
        formNew.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(formNew);
            fd.append('action','create');
            // Normalizar checkbox activo
            if (!fd.has('activo')) fd.append('activo','0');
            fetch('users_api.php', { method:'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res || res.ok !== true) throw new Error(res && res.error ? res.error : 'Error');
                    // limpiar campos mínimos
                    formNew.reset();
                    document.getElementById('u_activo').checked = true;
                    cargarUsuarios();
                })
                .catch(err => {
                    let msg = 'No se pudo crear';
                    if (err && err.message === 'DUPLICATE') msg = 'El usuario ya existe';
                    if (err && err.message === 'REQUIRED') msg = 'Complete usuario y contraseña';
                    alert(msg);
                });
        });
    }
    // Delegación de acciones en listado
    const lista = document.getElementById('lista-usuarios');
    if (lista) {
        lista.addEventListener('click', function(e){
            const tr = e.target.closest('tr[data-id]');
            if (!tr) return;
            const id = parseInt(tr.getAttribute('data-id')||'0',10) || 0;
            if (e.target.closest('.user-save')) {
                const usuario = tr.querySelector('.u-usuario')?.value.trim() || '';
                const nombre = tr.querySelector('.u-nombre')?.value.trim() || '';
                const rol = tr.querySelector('.u-rol')?.value || 'USER';
                const activo = tr.querySelector('.u-activo')?.checked ? '1' : '0';
                const fd = new FormData();
                fd.append('action','update'); fd.append('id', String(id)); fd.append('usuario', usuario); fd.append('nombre', nombre); fd.append('rol', rol); fd.append('activo', activo);
                fetch('users_api.php', { method:'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (!res || res.ok !== true) throw new Error(res && res.error ? res.error : 'Error');
                        // feedback
                        const ok = document.createElement('span'); ok.textContent = ' Guardado'; ok.style.color='#198754'; ok.style.marginLeft='6px'; ok.style.fontSize='12px';
                        e.target.insertAdjacentElement('afterend', ok); setTimeout(()=>ok.remove(), 1200);
                    })
                    .catch(err => {
                        let msg = 'No se pudo guardar';
                        if (err && err.message === 'DUPLICATE') msg = 'El usuario ya existe';
                        alert(msg);
                    });
            }
            if (e.target.closest('.user-reset')) {
                const nuevo = prompt('Nueva contraseña para este usuario:');
                if (!nuevo) return;
                const fd = new FormData(); fd.append('action','reset_password'); fd.append('id', String(id)); fd.append('password', nuevo);
                fetch('users_api.php', { method:'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (!res || res.ok !== true) throw new Error('Error');
                        alert('Contraseña actualizada');
                    })
                    .catch(()=>alert('No se pudo actualizar la contraseña'));
            }
        });
    }
});

// Rutas: listeners de formulario y eliminación
document.addEventListener('DOMContentLoaded', function(){
    const formRuta = document.getElementById('form-ruta');
    if (formRuta) {
        formRuta.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(formRuta);
            fd.append('action','save');
            fetch('rutas_api.php', { method:'POST', body: fd })
                .then(r => r.text())
                .then(html => { const cont = document.getElementById('lista-rutas'); if (cont) cont.innerHTML = html; })
                .catch(()=>alert('Error al guardar ruta'));
        });
        const lista = document.getElementById('lista-rutas');
        if (lista) {
            lista.addEventListener('click', function(e){
                const btn = e.target.closest('button[data-del]');
                if (!btn) return;
                const cod = btn.getAttribute('data-cod');
                const dia = btn.getAttribute('data-dia');
                fetch(`rutas_api.php?action=delete&cod=${encodeURIComponent(cod)}&dia=${encodeURIComponent(dia)}`)
                    .then(r => r.text())
                    .then(html => { this.innerHTML = html; })
                    .catch(()=>alert('Error al eliminar ruta'));
            });
        }
    }
});