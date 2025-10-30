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

// Mostrar módulo y cargar resumen automáticamente
window.mostrarModulo = function(modulo) {
    document.getElementById('modulo-subir').style.display = (modulo === 'subir') ? 'block' : 'none';
    document.getElementById('modulo-consultar').style.display = (modulo === 'consultar') ? 'block' : 'none';
    document.getElementById('modulo-resumen').style.display = (modulo === 'resumen') ? 'block' : 'none';
    const admin = document.getElementById('modulo-admin');
    if (admin) admin.style.display = (modulo === 'admin') ? 'block' : 'none';
        const devol = document.getElementById('modulo-devoluciones');
        if (devol) devol.style.display = (modulo === 'devoluciones') ? 'block' : 'none';
    if (modulo === 'resumen') {
        // Esperar a que el DOM renderice el input de fecha
        setTimeout(function() {
            setFechaHoyResumen();
            const fechaInput = document.getElementById('fecha_resumen');
            if (fechaInput) {
                cargarResumen(fechaInput.value);
            }
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
    }
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
        });
        // Actualizar resumen al cambiar supervisor
        const supervisorSelect = document.getElementById('supervisor_resumen');
        if (supervisorSelect) {
            supervisorSelect.addEventListener('change', function() {
                const fecha = document.getElementById('fecha_resumen').value;
                cargarResumen(fecha);
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

// Listener formulario devoluciones
document.addEventListener('DOMContentLoaded', function(){
    const formDev = document.getElementById('form-devoluciones');
    if (formDev) {
        formDev.addEventListener('submit', function(e){
            e.preventDefault();
            cargarDevoluciones();
        });
    }
    // Delegación para guardar formularios por camión
    const cont = document.getElementById('devoluciones');
    if (cont) {
        cont.addEventListener('submit', function(e){
            const formEstados = e.target.closest('form.form-estados');
            const formBulk = e.target.closest('form.form-bulk');
            if (formEstados || formBulk) {
                e.preventDefault();
                const form = formEstados || formBulk;
                const fd = new FormData(form);
                fetch('devoluciones_gestion.php', { method:'POST', body: fd })
                    .then(r => r.text())
                    .then(msg => {
                        // Mostrar mensaje y refrescar listado para actualizar conteos/restantes
                        const box = document.createElement('div');
                        box.innerHTML = msg;
                        form.insertAdjacentElement('beforebegin', box.firstChild);
                        // Pequeño retraso para ver el mensaje y refrescar
                        setTimeout(() => cargarDevoluciones(), 300);
                    })
                    .catch(()=>alert('Error al guardar estados'));
            }
        });
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