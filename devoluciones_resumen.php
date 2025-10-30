<?php
// devoluciones_resumen.php
// Muestra un listado de devoluciones por fecha con filtros opcionales
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$codVend = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
$codCli = isset($_GET['cod_cliente']) ? trim($_GET['cod_cliente']) : '';
$veh = isset($_GET['vehiculo']) ? trim($_GET['vehiculo']) : '';

if ($fecha === '') {
    echo '<div class="container"><p>Seleccione una fecha.</p></div>';
    exit;
}

$params = [];
$where = ['fecha = ?'];
$params[] = $fecha;
$types = 's';

if ($codVend !== '') { $where[] = 'codigovendedor = ?'; $params[] = $codVend; $types .= 's'; }
if ($codCli !== '') { $where[] = 'codigocliente = ?'; $params[] = $codCli; $types .= 's'; }
if ($veh !== '') { $where[] = 'vehiculo = ?'; $params[] = $veh; $types .= 's'; }

$sql = 'SELECT fecha, codigovendedor, nombrevendedor, codigocliente, nombrecliente, direccioncliente, codigoproducto, nombreproducto, cantidad, vehiculo
        FROM devoluciones_por_cliente
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY codigovendedor, codigocliente, codigoproducto';

$stmt = $mysqli->prepare($sql);
if (!$stmt) { die('Error preparando consulta: ' . esc($mysqli->error)); }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalRegs = count($data);
$totalCant = 0.0;
foreach ($data as $row) { $totalCant += (float)$row['cantidad']; }

ob_start();
?>
<div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <strong>Devoluciones del <?= esc($fecha) ?>:</strong>
        <span>Registros: <?= esc($totalRegs) ?></span>
        <span>Cantidad total: <?= number_format($totalCant, 2) ?></span>
        <button onclick="window.print()" style="margin-left:auto;">Imprimir PDF</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Cod_Vendedor</th>
                <th>Nom_Vendedor</th>
                <th>Cod_Cliente</th>
                <th>Nom_Cliente</th>
                <th>Dirección</th>
                <th>Cod_Producto</th>
                <th>Nom_Producto</th>
                <th>Cantidad</th>
                <th>Vehículo</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($totalRegs === 0): ?>
                <tr><td colspan="10" style="text-align:center;">Sin resultados</td></tr>
            <?php else: ?>
                <?php foreach ($data as $r): ?>
                    <tr>
                        <td><?= esc($r['fecha']) ?></td>
                        <td><?= esc($r['codigovendedor']) ?></td>
                        <td><?= esc($r['nombrevendedor']) ?></td>
                        <td><?= esc($r['codigocliente']) ?></td>
                        <td><?= esc($r['nombrecliente']) ?></td>
                        <td><?= esc($r['direccioncliente']) ?></td>
                        <td><?= esc($r['codigoproducto']) ?></td>
                        <td><?= esc($r['nombreproducto']) ?></td>
                        <td style="text-align:right;"><?= number_format((float)$r['cantidad'], 2) ?></td>
                        <td><?= esc($r['vehiculo']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$html = ob_get_clean();
echo $html;
