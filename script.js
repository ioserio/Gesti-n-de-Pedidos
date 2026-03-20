// Anti-cache global para todas las peticiones fetch: agrega _ts y headers no-cache
(function(){
    const BUILD_TS = Date.now();
    const origFetch = window.fetch;
    if (typeof origFetch === 'function') {
        window.fetch = function(input, init){
            try {
                let url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');
                if (url && url.indexOf('_ts=') === -1 && !url.startsWith('data:')) {
                    const sep = url.indexOf('?') === -1 ? '?' : '&';
                    url += sep + '_ts=' + BUILD_TS;
                    if (typeof input === 'string') {
                        input = url;
                    } else {
                        input = new Request(url, input);
                    }
                }
                if (!init) init = {};
                if (!init.headers) init.headers = {};
                if (init.headers instanceof Headers) {
                        init.headers.set('Cache-Control','no-cache');
                        init.headers.set('Pragma','no-cache');
                } else {
                        init.headers['Cache-Control'] = 'no-cache';
                        init.headers['Pragma'] = 'no-cache';
                }
            } catch(e) { /* silencioso */ }
            return origFetch.call(this, input, init);
        };
    }
})();

window.__themeStorageKey = 'rikflex-theme';

function applyTheme(mode) {
    const isDark = mode !== 'light';
    document.body.classList.toggle('dark-theme', isDark);
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    btn.setAttribute('title', isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
    btn.setAttribute('aria-label', isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
    const icon = btn.querySelector('.theme-toggle-icon');
    if (icon) icon.textContent = isDark ? '☾' : '☀';
}

function initThemeToggle() {
    let storedMode = 'dark';
    try {
        const saved = localStorage.getItem(window.__themeStorageKey);
        if (saved === 'light' || saved === 'dark') storedMode = saved;
    } catch (_) {}

    applyTheme(storedMode);

    const btn = document.getElementById('theme-toggle');
    if (!btn || btn.__themeWired) return;
    btn.__themeWired = true;
    btn.addEventListener('click', function() {
        const nextMode = document.body.classList.contains('dark-theme') ? 'light' : 'dark';
        applyTheme(nextMode);
        try {
            localStorage.setItem(window.__themeStorageKey, nextMode);
        } catch (_) {}
    });
}

// Función para poner la fecha de hoy en el input de resumen
function setFechaHoyResumen() {
    const fechaInput = document.getElementById('fecha_resumen');
    if (fechaInput && !fechaInput.value) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
}

// Lunes de la semana actual (YYYY-MM-DD)
function mondayOfCurrentWeek(){
    const d = new Date();
    const day = d.getDay(); // 0=Domingo
    const diff = d.getDate() - day + (day === 0 ? -6 : 1); // ajustar a lunes
    const monday = new Date(d.setDate(diff));
    monday.setHours(0,0,0,0);
    return monday.toISOString().slice(0,10);
}

// Fecha de hoy para seguimiento de pedidos
function setFechaHoySeguimiento(){
    const el = document.getElementById('fecha_seguimiento');
    if (el && !el.value) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        el.value = `${yyyy}-${mm}-${dd}`;
    }
}

window.__seguimientoSortPrev = window.__seguimientoSortPrev || '';
window.__seguimientoSortLast = window.__seguimientoSortLast || '';

    // Función para poner la fecha de hoy en el input de devoluciones
    function setFechaHoyDevoluciones() {
        const fechaInput = document.getElementById('fecha_dev');
        if (fechaInput && !fechaInput.value) {
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
        if (f1 && !f1.value) f1.value = val;
        if (f2 && !f2.value) f2.value = val;
        const fSingle = document.getElementById('fecha_recojo');
        if (fSingle && !fSingle.value) fSingle.value = val; // compatibilidad si existiera
    }

// Mostrar módulo y cargar resumen automáticamente
const MODULE_PERMISSION_MAP = {
    'subir':'importar',
    'consultar':'consultar',
    'seguimiento':'seguimiento',
    'resumen':'resumen',
    'herramientas':'herramientas',
    'cobranzas':'cobranzas',
    'devoluciones':'devoluciones',
    'recojos':'recojos',
    'admin':'admin',
    'cuota_mensual':'cuota_mensual',
    'cuotas_hist':'cuotas_hist',
    'rutas':'rutas',
    'ctacte_vendedor':'ctacte_vendedor',
    'usuarios':'usuarios',
    'permisos':'permisos',
    'sesiones':'usuarios',
    'almacen':'almacen',
    'inicio': null
};

const MODULE_TAB_META = {
    'inicio': { title: 'Inicio', closable: false },
    'subir': { title: 'Importar', closable: true },
    'consultar': { title: 'Consulta por vd', closable: true },
    'seguimiento': { title: 'Seguimiento', closable: true },
    'resumen': { title: 'Resumen', closable: true },
    'herramientas': { title: 'Herramientas', closable: true },
    'cobranzas': { title: 'Cobranzas', closable: true },
    'devoluciones': { title: 'Devoluciones', closable: true },
    'recojos': { title: 'Recojos', closable: true },
    'admin': { title: 'Admin Cuotas', closable: true },
    'cuota_mensual': { title: 'Cuota Mensual', closable: true },
    'rutas': { title: 'Rutas', closable: true },
    'ctacte_vendedor': { title: 'CTACTE/Vendedor', closable: true },
    'usuarios': { title: 'Usuarios', closable: true },
    'permisos': { title: 'Permisos', closable: true },
    'sesiones': { title: 'Usuarios en línea', closable: true },
    'almacen': { title: 'Almacén', closable: true }
};

window.__openModuleTabs = window.__openModuleTabs || ['inicio'];
window.__activeModuleTab = window.__activeModuleTab || 'inicio';
window.__draggingModuleTab = null;

function normalizeOpenModuleTabs(tabs) {
    const list = Array.isArray(tabs) ? tabs : [];
    const seen = new Set();
    const normalized = [];
    list.forEach(function(modulo) {
        if (!MODULE_TAB_META[modulo] || seen.has(modulo)) return;
        seen.add(modulo);
        normalized.push(modulo);
    });
    if (!normalized.length) normalized.push('inicio');
    if (!normalized.includes('inicio')) normalized.unshift('inicio');
    return normalized;
}

window.__openModuleTabs = normalizeOpenModuleTabs(window.__openModuleTabs);

function canAccessModule(modulo) {
    try {
        if (!modulo || !MODULE_PERMISSION_MAP.hasOwnProperty(modulo)) return true;
        const perm = MODULE_PERMISSION_MAP[modulo];
        if (!perm) return true;
        return !(window.__PERMS && window.__PERMS[perm] === false);
    } catch (_) {
        return true;
    }
}

function renderModuleTabs() {
    const host = document.getElementById('panel-tabs');
    if (!host) return;
    window.__openModuleTabs = normalizeOpenModuleTabs(window.__openModuleTabs);
    if (!window.__openModuleTabs.includes(window.__activeModuleTab)) {
        window.__activeModuleTab = window.__openModuleTabs[0] || 'inicio';
    }
    host.innerHTML = '';
    window.__openModuleTabs.forEach(function(modulo) {
        const meta = MODULE_TAB_META[modulo] || { title: modulo, closable: true };
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'panel-tab' + (window.__activeModuleTab === modulo ? ' is-active' : '');
        tab.setAttribute('data-module-tab', modulo);
        tab.title = meta.title;
        if (meta.closable !== false) {
            tab.draggable = true;
            tab.classList.add('is-draggable');
            tab.addEventListener('dragstart', function() {
                window.__draggingModuleTab = modulo;
                tab.classList.add('is-dragging');
            });
            tab.addEventListener('dragend', function() {
                window.__draggingModuleTab = null;
                tab.classList.remove('is-dragging');
                host.querySelectorAll('.panel-tab').forEach(function(item) {
                    item.classList.remove('drop-before', 'drop-after');
                });
            });
            tab.addEventListener('dragover', function(e) {
                if (!window.__draggingModuleTab || window.__draggingModuleTab === modulo) return;
                e.preventDefault();
                const rect = tab.getBoundingClientRect();
                const before = e.clientX < (rect.left + rect.width / 2);
                tab.classList.toggle('drop-before', before);
                tab.classList.toggle('drop-after', !before);
            });
            tab.addEventListener('dragleave', function() {
                tab.classList.remove('drop-before', 'drop-after');
            });
            tab.addEventListener('drop', function(e) {
                if (!window.__draggingModuleTab || window.__draggingModuleTab === modulo) return;
                e.preventDefault();
                const rect = tab.getBoundingClientRect();
                const before = e.clientX < (rect.left + rect.width / 2);
                reorderModuleTab(window.__draggingModuleTab, modulo, before);
            });
        }

        const label = document.createElement('span');
        label.className = 'panel-tab-label';
        label.textContent = meta.title;
        tab.appendChild(label);

        if (meta.closable !== false) {
            const close = document.createElement('span');
            close.className = 'panel-tab-close';
            close.setAttribute('data-close-module-tab', modulo);
            close.setAttribute('aria-hidden', 'true');
            close.textContent = '×';
            tab.appendChild(close);
        }

        tab.addEventListener('click', function(e) {
            const closeTarget = e.target.closest('[data-close-module-tab]');
            if (closeTarget) {
                e.stopPropagation();
                closeModuleTab(modulo);
                return;
            }
            activateModuleTab(modulo);
        });

        host.appendChild(tab);
    });
}

function reorderModuleTab(sourceModulo, targetModulo, insertBefore) {
    const tabs = normalizeOpenModuleTabs(window.__openModuleTabs);
    const sourceIndex = tabs.indexOf(sourceModulo);
    const targetIndex = tabs.indexOf(targetModulo);
    if (sourceIndex === -1 || targetIndex === -1 || sourceIndex === targetIndex) {
        window.__openModuleTabs = tabs;
        renderModuleTabs();
        return;
    }
    const moved = tabs.splice(sourceIndex, 1)[0];
    let nextIndex = tabs.indexOf(targetModulo);
    if (nextIndex === -1) nextIndex = tabs.length;
    if (!insertBefore) nextIndex += 1;
    tabs.splice(nextIndex, 0, moved);
    window.__openModuleTabs = normalizeOpenModuleTabs(tabs);
    renderModuleTabs();
}

function ensureModuleTab(modulo) {
    if (!MODULE_TAB_META[modulo]) return;
    window.__openModuleTabs = normalizeOpenModuleTabs(window.__openModuleTabs.concat(modulo));
}

function closeModuleTab(modulo) {
    const meta = MODULE_TAB_META[modulo] || { closable: true };
    if (meta.closable === false) return;
    saveModuleState(modulo);
    const currentTabs = window.__openModuleTabs.slice();
    const idx = currentTabs.indexOf(modulo);
    if (idx === -1) return;
    currentTabs.splice(idx, 1);
    if (!currentTabs.length) currentTabs.push('inicio');
    window.__openModuleTabs = normalizeOpenModuleTabs(currentTabs);
    if (window.__activeModuleTab === modulo) {
        const fallback = currentTabs[Math.max(0, idx - 1)] || currentTabs[0] || 'inicio';
        window.__activeModuleTab = fallback;
        activateModuleContent(fallback);
    } else {
        renderModuleTabs();
    }
}

function activateModuleTab(modulo) {
    if (!MODULE_TAB_META[modulo]) modulo = 'inicio';
    if (window.__activeModuleTab && window.__activeModuleTab !== modulo) {
        saveModuleState(window.__activeModuleTab);
    }
    window.__activeModuleTab = modulo;
    activateModuleContent(modulo);
}

function openModuleTab(modulo) {
    if (!canAccessModule(modulo)) {
        modulo = 'inicio';
    }
    if (!MODULE_TAB_META[modulo]) modulo = 'inicio';
    ensureModuleTab(modulo);
    activateModuleTab(modulo);
}

function getModuleSection(modulo) {
    const sectionIdMap = {
        inicio: 'modulo-inicio',
        subir: 'modulo-subir',
        consultar: 'modulo-consultar',
        seguimiento: 'modulo-seguimiento',
        resumen: 'modulo-resumen',
        herramientas: 'modulo-herramientas',
        cobranzas: 'modulo-cobranzas',
        devoluciones: 'modulo-devoluciones',
        recojos: 'modulo-recojos',
        admin: 'modulo-admin',
        cuota_mensual: 'modulo-cuota-mensual',
        rutas: 'modulo-rutas',
        ctacte_vendedor: 'modulo-ctacte-vendedor',
        usuarios: 'modulo-usuarios',
        permisos: 'modulo-permisos',
        sesiones: 'modulo-sesiones',
        almacen: 'modulo-almacen'
    };
    const sectionId = sectionIdMap[modulo];
    return sectionId ? document.getElementById(sectionId) : null;
}

window.__moduleTabState = window.__moduleTabState || {};

function saveModuleState(modulo) {
    const section = getModuleSection(modulo);
    if (!section) return;
    const state = {};
    section.querySelectorAll('input, select, textarea').forEach(function(field) {
        const key = field.id || field.name;
        if (!key || field.type === 'file') return;
        if (field.type === 'checkbox' || field.type === 'radio') state[key] = !!field.checked;
        else state[key] = field.value;
    });
    if (modulo === 'seguimiento') {
        state.__sortPrev = window.__seguimientoSortPrev || '';
        state.__sortLast = window.__seguimientoSortLast || '';
    }
    window.__moduleTabState[modulo] = state;
}

function restoreModuleState(modulo) {
    const section = getModuleSection(modulo);
    const state = window.__moduleTabState[modulo];
    if (!section || !state) return;
    Object.keys(state).forEach(function(key) {
        if (key === '__sortPrev' || key === '__sortLast') return;
        let field = document.getElementById(key);
        if (field && !section.contains(field)) field = null;
        if (!field) {
            field = section.querySelector('[name="' + key.replace(/"/g, '\\"') + '"]');
        }
        if (!field || field.type === 'file') return;
        if (field.type === 'checkbox' || field.type === 'radio') field.checked = !!state[key];
        else field.value = state[key];
    });
    if (modulo === 'seguimiento') {
        window.__seguimientoSortPrev = state.__sortPrev || '';
        window.__seguimientoSortLast = state.__sortLast || '';
    }
}

function activateModuleContent(modulo) {
    const inicio = document.getElementById('modulo-inicio');
    if (inicio) inicio.style.display = (modulo === 'inicio') ? 'block' : 'none';
    document.getElementById('modulo-subir').style.display = (modulo === 'subir') ? 'block' : 'none';
    document.getElementById('modulo-consultar').style.display = (modulo === 'consultar') ? 'block' : 'none';
    const seg = document.getElementById('modulo-seguimiento');
    if (seg) seg.style.display = (modulo === 'seguimiento') ? 'block' : 'none';
    document.getElementById('modulo-resumen').style.display = (modulo === 'resumen') ? 'block' : 'none';
    const cobr = document.getElementById('modulo-cobranzas');
    if (cobr) cobr.style.display = (modulo === 'cobranzas') ? 'block' : 'none';
    // Alternar modo ancho completo para módulos que requieren ocupar todo el ancho (cobranzas y devoluciones)
    const wrap = document.querySelector('.container');
    if (wrap) {
        if (modulo === 'cobranzas' || modulo === 'devoluciones') wrap.classList.add('fullwidth');
        else wrap.classList.remove('fullwidth');

        if (modulo === 'resumen') wrap.classList.add('resumenwide');
        else wrap.classList.remove('resumenwide');
    }
    const admin = document.getElementById('modulo-admin');
    if (admin) admin.style.display = (modulo === 'admin') ? 'block' : 'none';
    const cuotaMensual = document.getElementById('modulo-cuota-mensual');
    if (cuotaMensual) cuotaMensual.style.display = (modulo === 'cuota_mensual') ? 'block' : 'none';
        const herramientas = document.getElementById('modulo-herramientas');
        if (herramientas) herramientas.style.display = (modulo === 'herramientas') ? 'block' : 'none';
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
        const almacen = document.getElementById('modulo-almacen');
        if (almacen) almacen.style.display = (modulo === 'almacen') ? 'block' : 'none';
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
            restoreModuleState('resumen');
            setFechaHoyResumen();
            const fechaInput = document.getElementById('fecha_resumen');
            if (fechaInput) {
                cargarResumen(fechaInput.value);
                refreshResumenDashboard(fechaInput.value);
            }
            // Cargar la etiqueta de última actualización
            try { cargarUltimaActualizacion(); } catch(_) {}
        }, 100);
    } else if (modulo === 'seguimiento') {
        setTimeout(function(){
            restoreModuleState('seguimiento');
            setFechaHoySeguimiento();
            const f = document.getElementById('fecha_seguimiento');
            if (f && f.value) cargarSeguimiento(f.value, {
                sortPrev: window.__seguimientoSortPrev || '',
                sortLast: window.__seguimientoSortLast || ''
            });
        }, 100);
    } else if (modulo === 'admin') {
        // Cargar lista de cuotas
        setTimeout(function(){
            cargarCuotas();
            // Preparar listeners para plantilla masiva (legacy)
            const addBtn = document.getElementById('btn-add-mass-legacy');
            const saveBtn = document.getElementById('btn-save-mass-legacy');
            if (addBtn && !addBtn.__wired) { addBtn.__wired = true; addBtn.addEventListener('click', buildMassTemplateLegacy); }
            if (saveBtn && !saveBtn.__wired) { saveBtn.__wired = true; saveBtn.addEventListener('click', saveMassLegacy); }
        }, 50);
        } else if (modulo === 'cuota_mensual') {
            setTimeout(function(){ initCuotaMensualModule(); cargarCuotaMensual(); }, 50);
        } else if (modulo === 'devoluciones') {
            setTimeout(function(){
                restoreModuleState('devoluciones');
                setFechaHoyDevoluciones();
                const f = document.getElementById('fecha_dev');
                if (f) cargarDevoluciones();
            }, 100);
        } else if (modulo === 'recojos') {
            setTimeout(function(){
                restoreModuleState('recojos');
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
        } else if (modulo === 'herramientas') {
            setTimeout(function(){ initHerramientas(); }, 50);
        } else if (modulo === 'usuarios') {
            setTimeout(function(){ cargarUsuarios(); }, 50);
        } else if (modulo === 'sesiones') {
            setTimeout(function(){ cargarSesiones(); }, 50);
            if (window.__SESSIONS_TIMER) { clearInterval(window.__SESSIONS_TIMER); }
            window.__SESSIONS_TIMER = setInterval(function(){
                const ses = document.getElementById('modulo-sesiones');
                if (ses && ses.style.display !== 'none') cargarSesiones();
            }, 30000);
        } else if (modulo === 'almacen') {
            setTimeout(function(){ initAlmacen(); }, 50);
    }
    renderModuleTabs();
}

window.mostrarModulo = function(modulo) {
    openModuleTab(modulo || 'inicio');
}

// Herramientas: inicialización y acciones
let _toolsInited = false;
function initHerramientas(){
    if (_toolsInited) return; _toolsInited = true;
    const $msg = document.getElementById('tool-msg');
    const setMsg = (s,err)=>{ if($msg){ $msg.textContent=s||''; $msg.style.color = err?'#c0392b':'#2c3e50'; } };
    const btnDni = document.getElementById('btn-dni');
    const btnRuc = document.getElementById('btn-ruc');
    if (btnDni && !btnDni.__wired){
        btnDni.__wired = true;
        btnDni.addEventListener('click', async ()=>{
            const dni = (document.getElementById('dni_input').value||'').trim();
            if (!/^\d{8}$/.test(dni)){ setMsg('Ingrese DNI válido (8 dígitos)', true); return; }
            setMsg('Consultando DNI...');
            const r = await postJSON('tools_dni.php',{dni});
            if (!r || r.ok !== true){
                let msg = (r && r.error) ? r.error : 'No se pudo consultar DNI';
                if (r && r.detail) msg += ' ('+r.detail+')';
                if (r && r.raw) msg += ' ['+r.raw+']';
                setMsg(msg, true); return;
            }
                        // Mostrar intentos si existen (para depuración), incluso en éxito para saber variante
                        /*try {
                            if (r && Array.isArray(r.attempts)) {
                                let box = document.getElementById('tool-attempts');
                                if (!box) { box = document.createElement('pre'); box.id='tool-attempts'; box.style.padding='8px'; box.style.background='#f7f7f7'; box.style.border='1px solid #ddd'; box.style.fontSize='11px'; box.style.maxHeight='180px'; box.style.overflow='auto'; $msg.parentElement.insertBefore(box, $msg.nextSibling); }
                                const lines = r.attempts.map(a => `${a.provider} ${a.variant||''} -> ${a.http} ${a.body}`);
                                box.textContent = 'INTENTOS:\n' + lines.join('\n') + (r.auth_variant ? ('\nSeleccionado: '+r.source+' ('+r.auth_variant+')') : '');
                            }
                        } catch(_){} */
            setMsg('');
            document.getElementById('dni_res_dni').textContent = r.data.dni || '';
            document.getElementById('dni_res_nombres').textContent = r.data.nombres || '';
            document.getElementById('dni_res_apep').textContent = r.data.apellidoPaterno || '';
            document.getElementById('dni_res_apem').textContent = r.data.apellidoMaterno || '';
            document.getElementById('dni_res_full').textContent = r.data.nombreCompleto || '';
            // Campos extras si disponibles
            const genero = (r.data.genero || '').toString().trim();
            const fnac = (r.data.fechaNacimiento || '').toString().trim();
            const codv = (r.data.codigoVerificacion || '').toString().trim();
            const elGen = document.getElementById('dni_res_genero'); if (elGen) elGen.textContent = genero || '';
            const elFn  = document.getElementById('dni_res_fnac'); if (elFn) elFn.textContent = fnac || '';
            const elCv  = document.getElementById('dni_res_codver'); if (elCv) elCv.textContent = codv || '';
            document.getElementById('dni_result').hidden = false;
        });
    }
    if (btnRuc && !btnRuc.__wired){
        btnRuc.__wired = true;
        btnRuc.addEventListener('click', async ()=>{
            const ruc = (document.getElementById('ruc_input').value||'').trim();
            if (!/^\d{11}$/.test(ruc)){ setMsg('Ingrese RUC válido (11 dígitos)', true); return; }
            setMsg('Consultando RUC...');
            const r = await postJSON('tools_ruc.php',{ruc});
            if (!r || r.ok !== true){
                let msg = (r && r.error) ? r.error : 'No se pudo consultar RUC';
                if (r && r.detail) msg += ' ('+r.detail+')';
                if (r && r.raw) msg += ' ['+r.raw+']';
                setMsg(msg, true); return;
            }
                        /*try {
                            if (r && Array.isArray(r.attempts)) {
                                let box = document.getElementById('tool-attempts');
                                if (!box) { box = document.createElement('pre'); box.id='tool-attempts'; box.style.padding='8px'; box.style.background='#f7f7f7'; box.style.border='1px solid #ddd'; box.style.fontSize='11px'; box.style.maxHeight='180px'; box.style.overflow='auto'; $msg.parentElement.insertBefore(box, $msg.nextSibling); }
                                const lines = r.attempts.map(a => `${a.provider} ${a.variant||''} -> ${a.http} ${a.body}`);
                                box.textContent = 'INTENTOS:\n' + lines.join('\n') + (r.auth_variant ? ('\nSeleccionado: '+r.source+' ('+r.auth_variant+')') : '');
                            }
                        } catch(_){} */
            setMsg('');
            document.getElementById('ruc_res_ruc').textContent = r.data.ruc || '';
            document.getElementById('ruc_res_razon').textContent = r.data.razonSocial || '';
            document.getElementById('ruc_res_estado').textContent = r.data.estado || '';
            document.getElementById('ruc_res_cond').textContent = r.data.condicion || '';
            document.getElementById('ruc_res_dir').textContent = r.data.direccion || '';
            document.getElementById('ruc_result').hidden = false;
        });
    }
}

async function postJSON(url, payload){
    try{
        const resp = await fetch(url,{
            method:'POST',
            headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify(payload||{})
        });
        const text = await resp.text();
        try { return JSON.parse(text); } catch(e){ return { ok:false, error:`${resp.status} ${resp.statusText}`, raw: text && text.slice ? text.slice(0,180) : '' }; }
    }catch(e){ return { ok:false, error: (e && e.message) ? e.message : 'Network error' }; }
}

let __importToastTimer = null;
function ensureImportToastHost(){
    let host = document.getElementById('import-toast-host');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'import-toast-host';
    host.className = 'import-toast-host';
    document.body.appendChild(host);
    return host;
}

function showImportToast(message, kind){
    const host = ensureImportToastHost();
    const toast = document.createElement('div');
    toast.className = 'import-toast ' + (kind === 'error' ? 'is-error' : 'is-success');
    toast.textContent = message || (kind === 'error' ? 'No se pudo completar la importacion.' : 'Importacion completada.');

    host.innerHTML = '';
    host.appendChild(toast);

    requestAnimationFrame(function(){
        toast.classList.add('is-visible');
    });

    if (__importToastTimer) clearTimeout(__importToastTimer);
    __importToastTimer = setTimeout(function(){
        toast.classList.remove('is-visible');
        setTimeout(function(){
            if (toast.parentElement) toast.remove();
        }, 250);
    }, 3000);
}

function clearImportFileInputs(form){
    form.querySelectorAll('input[type="file"]').forEach(function(input){ input.value = ''; });
}

async function submitImportFormAjax(form){
    const action = form.getAttribute('action') || window.location.href;
    const method = (form.getAttribute('method') || 'POST').toUpperCase();
    const fd = new FormData(form);

    const resp = await fetch(action, {
        method: method,
        body: fd,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    });

    const ct = (resp.headers.get('content-type') || '').toLowerCase();
    let payload = null;

    if (ct.indexOf('application/json') !== -1) {
        payload = await resp.json().catch(function(){ return null; });
    } else {
        const raw = await resp.text();
        const plain = (raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        payload = { ok: resp.ok, message: plain };
    }

    if (!resp.ok || !payload || payload.ok !== true) {
        const msg = (payload && payload.message) ? payload.message : ('Error al importar (HTTP ' + resp.status + ')');
        throw new Error(msg);
    }

    showImportToast(payload.message || 'Importacion completada correctamente.', 'success');
    clearImportFileInputs(form);

    if (form.closest('#modulo-subir')) {
        const preview = document.getElementById('preview');
        if (preview) preview.innerHTML = '';
    }
    if (form.closest('#modulo-almacen')) {
        try { cargarAlmacen(); } catch(_) {}
    }
}

function initImportFormsAjax(){
    const selector = '#modulo-subir form[enctype="multipart/form-data"], #modulo-almacen form[enctype="multipart/form-data"]';
    document.querySelectorAll(selector).forEach(function(form){
        if (form.__ajaxImportBound) return;
        form.__ajaxImportBound = true;

        form.addEventListener('submit', async function(e){
            e.preventDefault();
            const submitBtn = e.submitter || form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.textContent : '';

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Subiendo...';
            }

            try {
                await submitImportFormAjax(form);
            } catch (err) {
                const msg = (err && err.message) ? err.message : 'No se pudo completar la importacion.';
                showImportToast(msg, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText || 'Subir';
                }
            }
        });
    });
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
    const groupSup = document.getElementById('group_sup_resumen') ? document.getElementById('group_sup_resumen').checked : false;
    if (resumen) {
        resumen.innerHTML = '<p>Cargando...</p>';
        let url = 'resumen_pedidos.php?fecha=' + encodeURIComponent(fecha);
        if (supervisor) {
            url += '&supervisor=' + encodeURIComponent(supervisor);
        }
        if (groupSup) {
            url += '&group_supervisor=1';
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

function cargarResumenHistorico(fecha) {
    const cont = document.getElementById('resumen-historico');
    const supervisor = document.getElementById('supervisor_resumen') ? document.getElementById('supervisor_resumen').value : '';
    if (!cont) return;
    cont.innerHTML = '<p>Cargando histórico...</p>';

    let url = 'resumen_historico.php?fecha=' + encodeURIComponent(fecha || '');
    if (supervisor) {
        url += '&supervisor=' + encodeURIComponent(supervisor);
    }

    fetch(url)
        .then(res => res.text())
        .then(html => {
            cont.innerHTML = html;
        })
        .catch(() => {
            cont.innerHTML = '<p>Error al consultar histórico.</p>';
        });
}

function canUseDashboardAdm() {
    return !!(window.__PERMS && window.__PERMS.dashboard_adm === true);
}

function isDashboardAdmChecked() {
    const chk = document.getElementById('resumen_adm_chk');
    return !!(chk && chk.checked);
}

function syncResumenAdmControl() {
    const wrap = document.getElementById('resumen-adm-wrap');
    const chk = document.getElementById('resumen_adm_chk');
    if (!wrap || !chk) return;

    const allowed = canUseDashboardAdm();
    wrap.style.display = 'inline-flex';
    wrap.classList.toggle('is-disabled', !allowed);

    if (allowed) {
        chk.disabled = false;
        chk.removeAttribute('title');
        if (!chk.dataset.inited) {
            chk.checked = true;
            chk.dataset.inited = '1';
        }
    } else {
        chk.disabled = true;
        chk.title = 'Sin permiso para Dashboard ADM';
        chk.checked = false;
        delete chk.dataset.inited;
    }
}

function refreshResumenDashboard(fecha) {
    const layout = document.getElementById('resumen-layout');
    const aside = document.getElementById('resumen-historico');
    syncResumenAdmControl();

    const dashboardOn = canUseDashboardAdm() && isDashboardAdmChecked();
    if (layout) layout.classList.toggle('resumen-only', !dashboardOn);
    if (!aside) return;

    if (!dashboardOn) {
        aside.style.display = 'none';
        aside.innerHTML = '';
        return;
    }

    aside.style.display = '';
    cargarResumenHistorico(fecha);
}

// Cargar seguimiento por rangos horarios
function cargarSeguimiento(fecha, sortState){
    const cont = document.getElementById('seguimiento');
    if (!cont) return;
    cont.innerHTML = '<p>Cargando...</p>';
    const supervisor = document.getElementById('supervisor_seguimiento') ? document.getElementById('supervisor_seguimiento').value : '';
    const state = (sortState && typeof sortState === 'object') ? sortState : {};
    const sortPrev = (typeof state.sortPrev === 'string') ? state.sortPrev : (window.__seguimientoSortPrev || '');
    const sortLast = (typeof state.sortLast === 'string') ? state.sortLast : (window.__seguimientoSortLast || '');
    window.__seguimientoSortPrev = sortPrev;
    window.__seguimientoSortLast = sortLast;
    let url = 'seguimiento_pedidos.php?fecha=' + encodeURIComponent(fecha || '');
    if (supervisor) url += '&supervisor=' + encodeURIComponent(supervisor);
    if (sortPrev) url += '&sort_prev=' + encodeURIComponent(sortPrev);
    if (sortLast) url += '&sort_last=' + encodeURIComponent(sortLast);
    fetch(url)
        .then(r => r.text())
        .then(html => {
            cont.innerHTML = html;
            const sortPrevBtn = cont.querySelector('[data-sort-prev]');
            const sortBtn = cont.querySelector('[data-sort-last]');
            if (sortPrevBtn) {
                sortPrevBtn.addEventListener('click', function(){
                    const fechaActual = document.getElementById('fecha_seguimiento') ? document.getElementById('fecha_seguimiento').value : '';
                    const nextSortPrev = this.getAttribute('data-sort-prev');
                    cargarSeguimiento(fechaActual, {
                        sortPrev: nextSortPrev === null ? 'asc' : nextSortPrev,
                        sortLast: ''
                    });
                });
            }
            if (sortBtn) {
                sortBtn.addEventListener('click', function(){
                    const fechaActual = document.getElementById('fecha_seguimiento') ? document.getElementById('fecha_seguimiento').value : '';
                    const nextSort = this.getAttribute('data-sort-last');
                    cargarSeguimiento(fechaActual, {
                        sortPrev: '',
                        sortLast: nextSort === null ? 'asc' : nextSort
                    });
                });
            }
        })
        .catch(() => { cont.innerHTML = '<p>Error al consultar seguimiento.</p>'; });
}

// Evento para el formulario de resumen
document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();
    // Flag de dispositivo móvil para forzar layout responsive aunque haya CSS viejo cacheado
    function applyMobileFlag(){
        try {
            if (window.innerWidth <= 700) document.body.classList.add('is-mobile');
            else document.body.classList.remove('is-mobile');
        } catch(_){}
    }
    applyMobileFlag();
    window.addEventListener('resize', applyMobileFlag);

    initImportFormsAjax();

    const formResumen = document.getElementById('form-resumen');
    if (formResumen) {
        formResumen.addEventListener('submit', function(e) {
            e.preventDefault();
            const fecha = document.getElementById('fecha_resumen').value;
            cargarResumen(fecha);
            refreshResumenDashboard(fecha);
            // Refrescar la última actualización por si hubo cambios recientes
            try { cargarUltimaActualizacion(); } catch(_) {}
        });
        // Actualizar resumen al cambiar supervisor
        const supervisorSelect = document.getElementById('supervisor_resumen');
        if (supervisorSelect) {
            supervisorSelect.addEventListener('change', function() {
                const fecha = document.getElementById('fecha_resumen').value;
                cargarResumen(fecha);
                refreshResumenDashboard(fecha);
                try { cargarUltimaActualizacion(); } catch(_) {}
            });
        }
        const groupSupChk = document.getElementById('group_sup_resumen');
        if (groupSupChk) {
            groupSupChk.addEventListener('change', function(){
                const fecha = document.getElementById('fecha_resumen').value;
                cargarResumen(fecha);
                refreshResumenDashboard(fecha);
                try { cargarUltimaActualizacion(); } catch(_) {}
            });
        }
        const admChk = document.getElementById('resumen_adm_chk');
        if (admChk) {
            admChk.addEventListener('change', function(){
                const fecha = document.getElementById('fecha_resumen').value;
                refreshResumenDashboard(fecha);
            });
        }
        syncResumenAdmControl();
    }
    // Evento para Seguimiento de Pedidos
    const formSeg = document.getElementById('form-seguimiento');
    if (formSeg) {
        formSeg.addEventListener('submit', function(e){
            e.preventDefault();
            const fecha = document.getElementById('fecha_seguimiento').value;
            cargarSeguimiento(fecha);
        });
        const supSeg = document.getElementById('supervisor_seguimiento');
        if (supSeg) {
            supSeg.addEventListener('change', function(){
                const fecha = document.getElementById('fecha_seguimiento').value;
                cargarSeguimiento(fecha);
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

// Almacén: inicialización y carga
function setFechaHoyAlmacen(){
        const val = new Date().toISOString().slice(0,10);
        const a1 = document.getElementById('alm_fecha'); if (a1) a1.value = val;
        const a2 = document.getElementById('alm_fecha_map'); if (a2) a2.value = val;
        const a3 = document.getElementById('alm_list_fecha'); if (a3) a3.value = val;
}
function initAlmacen(){
        setFechaHoyAlmacen();
        const form = document.getElementById('alm-list-form');
        if (form && !form.__wired){
                form.__wired = true;
                form.addEventListener('submit', function(e){ e.preventDefault(); cargarAlmacen(); });
        }
        cargarAlmacen();
}
function cargarAlmacen(){
        const cont = document.getElementById('almacen-lista'); if (!cont) return;
        cont.innerHTML = '<p>Cargando...</p>';
        const fecha = document.getElementById('alm_list_fecha')?.value || '';
        const qs = new URLSearchParams(); if (fecha) qs.set('fecha', fecha);
        fetch('almacen_api.php?action=list&'+qs.toString())
            .then(r=>r.text()).then(html=>{
                cont.innerHTML = html;
                // delegar guardado: P.Real y Observación
                cont.addEventListener('change', function(e){
                        const isPrea = !!e.target.closest('.alm-prea');
                        const isObs  = !!e.target.closest('.alm-obs');
                        if (!isPrea && !isObs) return;
                        const inp = e.target;
                        const tr = inp.closest('tr'); if (!tr) return;
                        const id = tr.getAttribute('data-id');
                        const val = inp.value || '';
                        const fd = new FormData(); fd.append('action','save'); fd.append('id', id);
                        if (isPrea) fd.append('p_rea', val); else fd.append('observacion', val);
                        fetch('almacen_api.php', { method:'POST', body: fd })
                            .then(r=>r.json()).then(d=>{ if (d && d.ok) { inp.style.background='#e8f5e9'; setTimeout(()=>{inp.style.background='';}, 800); } else { alert('No se pudo guardar'); } })
                            .catch(()=>alert('Error'));
                });
            })
            .catch(()=>{ cont.innerHTML = '<p>Error al cargar Almacén.</p>'; });
}

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
        .then(html => { cont.innerHTML = html; try { attachRecojosCardHandlers(); } catch(_){} })
        .catch((err) => { cont.innerHTML = '<p>Error al consultar recojos: ' + (err.message||'') + '</p>'; });
}

function attachRecojosCardHandlers(){
    const cards = document.querySelectorAll('#recojos .rk-card');
    // Bind per card only once
    cards.forEach(card => {
        if (card.__rkBound) return; card.__rkBound = true;
        const openModal = () => openRecojoModal(card);
        // Single click/tap opens modal for better mobile UX
        card.addEventListener('click', openModal);
        // Keyboard accessibility
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter') openModal(); });
        // Keep dblclick inline toggle as a fallback (desktop)
        const detail = card.querySelector('.rk-detail');
        if (detail) {
            const toggleInline = () => {
                const isOpen = detail.style.display !== 'none';
                detail.style.display = isOpen ? 'none' : 'block';
                card.classList.toggle('open', !isOpen);
            };
            card.addEventListener('dblclick', (e) => { e.preventDefault(); toggleInline(); });
        }
    });
}

function ensureRecojoModal(){
    let overlay = document.getElementById('rk-modal-overlay');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'rk-modal-overlay';
    overlay.className = 'rk-modal-overlay';
    overlay.innerHTML = '<div class="rk-modal" role="dialog" aria-modal="true" aria-labelledby="rk-modal-title">'
        + '<div class="rk-modal-header"><h3 id="rk-modal-title">Detalle de Recojo</h3><button type="button" class="rk-modal-close" aria-label="Cerrar">×</button></div>'
        + '<div class="rk-modal-body"></div>'
        + '</div>';
    document.body.appendChild(overlay);
    // Close handlers
    const close = () => closeRecojoModal();
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.querySelector('.rk-modal-close').addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('open')) close(); });
    return overlay;
}

function openRecojoModal(card){
    const overlay = ensureRecojoModal();
    const body = overlay.querySelector('.rk-modal-body');
    // Build content from the card
    const fecha = card.querySelector('.rk-fecha')?.textContent || '';
    const vdVeh = card.querySelector('.rk-vd')?.textContent || '';
    const cliCod = card.querySelector('.rk-body .rk-line .rk-val')?.textContent || '';
    const cliName = card.querySelector('.rk-body .rk-wide .rk-val')?.getAttribute('title') || card.querySelector('.rk-body .rk-wide .rk-val')?.textContent || '';
    const detail = card.querySelector('.rk-detail');
    const detailHTML = detail ? detail.innerHTML : '<div class="rk-drow"><em>Sin detalle</em></div>';
    body.innerHTML = ''
        + '<div class="rk-modal-meta">'
        + '<div><b>Fecha:</b> ' + escapeHtml(fecha) + '</div>'
        + '<div><b>VD/CM:</b> ' + escapeHtml(vdVeh) + '</div>'
        + '<div><b>Cliente:</b> ' + escapeHtml(cliCod) + ' — ' + escapeHtml(cliName) + '</div>'
        + '</div>'
        + '<div class="rk-modal-detail">' + detailHTML + '</div>';
    overlay.classList.add('open');
}

function closeRecojoModal(){
    const overlay = document.getElementById('rk-modal-overlay');
    if (overlay) overlay.classList.remove('open');
}

function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'} )[c];
    });
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
                        tgt.textContent = nom ? `Hola,\n${nom}` : '';
                    }
            // Menú desplegable usuario
            const userMenu = document.getElementById('user-menu');
            if (tgt && userMenu) {
                function toggleMenu(show) {
                    userMenu.style.display = show ? 'block' : 'none';
                }
                tgt.addEventListener('click', function(e){
                    e.stopPropagation();
                    toggleMenu(userMenu.style.display !== 'block');
                });
                tgt.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleMenu(userMenu.style.display !== 'block');
                    }
                });
                document.addEventListener('click', function(e){
                    if (!userMenu.contains(e.target) && e.target !== tgt) {
                        toggleMenu(false);
                    }
                });
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
                        syncResumenAdmControl();
                        const secResumen = document.getElementById('modulo-resumen');
                        const fechaResumen = document.getElementById('fecha_resumen');
                        if (secResumen && secResumen.style.display !== 'none' && fechaResumen && fechaResumen.value) {
                            refreshResumenDashboard(fechaResumen.value);
                        }
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
        cargarResumen(fecha);
        refreshResumenDashboard(fecha);
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

// Admin legacy: construir plantilla masiva
function buildMassTemplateLegacy(){
    const massZone = document.getElementById('mass-zone-legacy');
    const massTable = document.getElementById('mass-table-legacy');
    const massMsg = document.getElementById('mass-msg-legacy');
    if (!massZone || !massTable || !massMsg) return;
    const lunes = mondayOfCurrentWeek();
    massMsg.textContent = 'Cargando lista de vendedores...';
    fetch('vendors_api.php', { headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' } })
        .then(async r => {
            const text = await r.text();
            let data; try { data = JSON.parse(text); } catch(e){ throw new Error((text||'').slice(0,200) || ('HTTP '+r.status)); }
            return data;
        })
        .then(data => {
            if (!data || data.ok !== true || !Array.isArray(data.vendors)) throw new Error('No se pudo obtener vendedores');
            const vendors = data.vendors; // [{cod, nombre}]
            // Orden ya viene ascendente y filtrado 001..997 desde vendors_api
            const dayNames = {1:'Lunes',2:'Martes',3:'Miércoles',4:'Jueves',5:'Viernes',6:'Sábado'};
            const rows = [];
            rows.push('<table class="resumen-desktop"><thead><tr><th>Cod_Vendedor</th><th>Nombre</th><th>Día</th><th>Cuota (S/)</th><th>Vigente desde</th></tr></thead><tbody>');
            for (let d=1; d<=6; d++) {
                for (let i=0;i<vendors.length;i++){
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
            const totalRows = vendors.length * 6;
            massMsg.textContent = 'Plantilla generada: Lunes→Sábado con ' + vendors.length + ' vendedores (total ' + totalRows.toLocaleString('es-PE') + ' filas). Ingresa montos y luego pulsa "Guardar cambios".';
        })
        .catch(err => { massMsg.textContent = 'No se pudo cargar vendedores ('+(err && err.message ? err.message : 'error')+').'; });
}

// Admin legacy: guardar plantilla masiva
function saveMassLegacy(){
    const massTable = document.getElementById('mass-table-legacy');
    const massMsg = document.getElementById('mass-msg-legacy');
    if (!massTable || !massMsg) return;
    const rows = Array.from(massTable.querySelectorAll('.mass-row'));
    if (!rows.length) { massMsg.textContent = 'No hay filas para guardar.'; return; }
    const items = [];
    rows.forEach(tr => {
        const cuotaEl = tr.querySelector('.mass-cuota');
        const vigEl = tr.querySelector('.mass-vigente');
        const val = parseFloat((cuotaEl && cuotaEl.value) || '0');
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
            massMsg.textContent = 'Guardadas: ' + j.saved + '. Omitidas: ' + j.skipped + '.';
            cargarCuotas();
        })
        .catch(err => { massMsg.textContent = 'Error: ' + (err.message||''); });
}

function setCuotaMensualDefaults(){
    const now = new Date();
    const anio = now.getFullYear();
    const mes = now.getMonth() + 1;
    const cmAnio = document.getElementById('cm_anio');
    const cmMes = document.getElementById('cm_mes');
    const fAnio = document.getElementById('cmf_anio');
    const fMes = document.getElementById('cmf_mes');
    if (cmAnio && !cmAnio.value) cmAnio.value = String(anio);
    if (cmMes && !cmMes.value) cmMes.value = String(mes);
    if (fAnio && !fAnio.value) fAnio.value = String(anio);
    if (fMes && !fMes.value) fMes.value = String(mes);
}

function limpiarFormCuotaMensual(){
    const form = document.getElementById('form-cuota-mensual');
    if (!form) return;
    form.reset();
    const id = document.getElementById('cm_id');
    if (id) id.value = '';
    const cancel = document.getElementById('cm_cancel');
    if (cancel) cancel.style.display = 'none';
    setCuotaMensualDefaults();
}

function cargarCuotaMensual(){
    const cont = document.getElementById('cm_lista');
    const anio = document.getElementById('cmf_anio') ? document.getElementById('cmf_anio').value.trim() : '';
    const mes = document.getElementById('cmf_mes') ? document.getElementById('cmf_mes').value.trim() : '';
    if (!cont) return;
    cont.innerHTML = '<p>Cargando cuotas mensuales...</p>';

    const params = new URLSearchParams();
    params.append('action', 'list');
    if (anio) params.append('anio', anio);
    if (mes) params.append('mes', mes);

    fetch('cuota_mensual_api.php?' + params.toString())
        .then(r => r.text())
        .then(html => { cont.innerHTML = html; })
        .catch(() => { cont.innerHTML = '<p>Error al cargar cuotas mensuales.</p>'; });
}

function initCuotaMensualModule(){
    const form = document.getElementById('form-cuota-mensual');
    const formFiltro = document.getElementById('form-cuota-mensual-filtro');
    const lista = document.getElementById('cm_lista');
    const msg = document.getElementById('cm_msg');
    const cancel = document.getElementById('cm_cancel');

    if (!form || !formFiltro || !lista || !msg || !cancel) return;
    if (form.__wiredCuotaMensual) return;
    form.__wiredCuotaMensual = true;

    setCuotaMensualDefaults();

    form.addEventListener('submit', function(e){
        e.preventDefault();
        const fd = new FormData(form);
        fd.append('action', 'save');
        msg.textContent = 'Guardando...';
        fetch('cuota_mensual_api.php?action=save', { method:'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!j || j.ok !== true) throw new Error(j && j.error ? j.error : 'Error');
                msg.style.color = '#198754';
                msg.textContent = 'Guardado correctamente.';
                limpiarFormCuotaMensual();
                cargarCuotaMensual();
            })
            .catch(err => {
                msg.style.color = '#b91c1c';
                msg.textContent = 'No se pudo guardar (' + (err.message || 'error') + ').';
            });
    });

    formFiltro.addEventListener('submit', function(e){
        e.preventDefault();
        cargarCuotaMensual();
    });

    const clearBtn = document.getElementById('cmf_clear');
    if (clearBtn && !clearBtn.__wired) {
        clearBtn.__wired = true;
        clearBtn.addEventListener('click', function(){
            const fy = document.getElementById('cmf_anio');
            const fm = document.getElementById('cmf_mes');
            if (fy) fy.value = '';
            if (fm) fm.value = '';
            cargarCuotaMensual();
        });
    }

    cancel.addEventListener('click', function(){
        limpiarFormCuotaMensual();
        msg.style.color = '#555';
        msg.textContent = 'Edición cancelada.';
    });

    lista.addEventListener('click', function(e){
        const btnEdit = e.target.closest('.cm-edit');
        if (btnEdit) {
            const id = btnEdit.getAttribute('data-id') || '';
            if (!id) return;
            fetch('cuota_mensual_api.php?action=get&id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(j => {
                    if (!j || j.ok !== true || !j.row) throw new Error(j && j.error ? j.error : 'Error');
                    const row = j.row;
                    document.getElementById('cm_id').value = row.id || '';
                    document.getElementById('cm_anio').value = row.Anio || '';
                    document.getElementById('cm_mes').value = row.Mes || '';
                    document.getElementById('cm_cuota').value = row.Cuota || '';
                    cancel.style.display = 'inline-block';
                    msg.style.color = '#555';
                    msg.textContent = 'Modo edición activo.';
                })
                .catch(err => {
                    msg.style.color = '#b91c1c';
                    msg.textContent = 'No se pudo cargar el registro (' + (err.message || 'error') + ').';
                });
            return;
        }

        const btnDel = e.target.closest('.cm-del');
        if (btnDel) {
            const id = btnDel.getAttribute('data-id') || '';
            if (!id) return;
            if (!confirm('¿Eliminar esta cuota mensual?')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch('cuota_mensual_api.php?action=delete', { method:'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (!j || j.ok !== true) throw new Error(j && j.error ? j.error : 'Error');
                    msg.style.color = '#198754';
                    msg.textContent = 'Registro eliminado.';
                    limpiarFormCuotaMensual();
                    cargarCuotaMensual();
                })
                .catch(err => {
                    msg.style.color = '#b91c1c';
                    msg.textContent = 'No se pudo eliminar (' + (err.message || 'error') + ').';
                });
        }
    });
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

// Menú móvil: clonar menú de escritorio y manejar toggles
document.addEventListener('DOMContentLoaded', function(){
    const nav = document.querySelector('nav');
    const desktopMenu = nav ? nav.querySelector('ul.menu') : null;
    const panel = document.getElementById('mobile-menu');
    const btn = document.querySelector('.mobile-nav-toggle');
    if (!desktopMenu || !panel || !btn) return;
    // Clonar estructura al panel
    const clone = desktopMenu.cloneNode(true);
    // limpiar clases específicas de layout
    clone.classList.remove('menu');
    panel.innerHTML = '';
    panel.appendChild(clone);
    // Marcar items con submenú para toggle por clic
    panel.querySelectorAll('li.has-submenu > a').forEach(a => {
        a.addEventListener('click', function(e){
            e.preventDefault();
            const li = a.parentElement;
            const open = li.classList.contains('open');
            // cerrar otros
            panel.querySelectorAll('li.has-submenu.open').forEach(el => el.classList.remove('open'));
            li.classList.toggle('open', !open);
        });
    });
    // Botón hamburguesa abre/cierra panel
    function togglePanel(force){
        const isOpen = panel.classList.contains('open');
        const next = (force==null) ? !isOpen : !!force;
        panel.classList.toggle('open', next);
        btn.setAttribute('aria-expanded', String(next));
        panel.setAttribute('aria-hidden', String(!next));
        document.body.style.overflow = next ? 'hidden' : '';
    }
    btn.addEventListener('click', function(){ togglePanel(); });
    // Cerrar al tocar fuera
    document.addEventListener('click', function(e){
        if (!panel.contains(e.target) && !btn.contains(e.target)) togglePanel(false);
    });
    // Reaplicar permisos a enlaces clonados cuando se actualicen
    try {
        const observer = new MutationObserver(() => {
            // Copiar clases is-enabled/is-disabled de desktop a mobile
            panel.querySelectorAll('a[data-mod]').forEach(a => {
                const mod = a.getAttribute('data-mod');
                const desk = desktopMenu.querySelector('a[data-mod="'+mod+'"]');
                if (desk) {
                    a.classList.toggle('is-enabled', desk.classList.contains('is-enabled'));
                    a.classList.toggle('is-disabled', desk.classList.contains('is-disabled'));
                }
            });
        });
        observer.observe(desktopMenu, { attributes:true, subtree:true, attributeFilter:['class'] });
    } catch(_){}
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