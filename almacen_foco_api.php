<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

function foco_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function foco_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function foco_normalize_vendor_code($code): string {
    $digits = preg_replace('/\D+/', '', trim((string)$code));
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (strlen($digits) > 3) {
        $digits = substr($digits, -3);
    }
    return str_pad($digits, 3, '0', STR_PAD_LEFT);
}

function foco_normalize_mesa_label($mesa): string {
    $mesa = trim((string)$mesa);
    if ($mesa === '') {
        return '';
    }
    if (preg_match('/(\d+)/', $mesa, $matches)) {
        return ltrim($matches[1], '0') ?: '0';
    }
    return strtoupper($mesa);
}

function foco_is_allowed_mesa($mesa): bool {
    $normalized = foco_normalize_mesa_label($mesa);
    if ($normalized === '' || $normalized === 'SIN MESA') {
        return false;
    }
    return $normalized !== '6';
}

function foco_parse_date($value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function foco_table_exists(mysqli $mysqli, string $table): bool {
    $safe = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
}

function foco_get_columns(mysqli $mysqli, string $table): array {
    $cols = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
        $res->close();
    }
    return $cols;
}

function foco_ensure_tables(mysqli $mysqli): void {
    $mysqli->query("CREATE TABLE IF NOT EXISTS productos_foco (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo_producto VARCHAR(50) NOT NULL,
        nombre_producto VARCHAR(255) NOT NULL DEFAULT '',
        fecha_inicio DATE DEFAULT NULL,
        fecha_fin DATE DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observacion VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_producto_foco_codigo (codigo_producto),
        KEY idx_producto_foco_fechas (fecha_inicio, fecha_fin),
        KEY idx_producto_foco_activo (activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $mysqli->query("CREATE TABLE IF NOT EXISTS productos_foco_meta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_foco_id INT NOT NULL,
        meta_cantidad DECIMAL(15,2) NOT NULL DEFAULT 0,
        fecha_inicio DATE DEFAULT NULL,
        fecha_fin DATE DEFAULT NULL,
        cod_vendedor VARCHAR(20) DEFAULT NULL,
        supervisor_id INT DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observacion VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_producto_foco_meta_producto (producto_foco_id),
        KEY idx_producto_foco_meta_vendedor (cod_vendedor),
        KEY idx_producto_foco_meta_supervisor (supervisor_id),
        KEY idx_producto_foco_meta_fechas (fecha_inicio, fecha_fin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $cols = foco_get_columns($mysqli, 'productos_foco_meta');
        if (!isset($cols['created_at'])) {
            @$mysqli->query("ALTER TABLE productos_foco_meta ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER observacion");
        }
        if (!isset($cols['updated_at'])) {
            @$mysqli->query("ALTER TABLE productos_foco_meta ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }
    } catch (Throwable $e) {
        // noop
    }
}

function foco_fetch_supervisores(mysqli $mysqli): array {
    $items = [];
    if (!foco_table_exists($mysqli, 'supervisores_ventas')) {
        return $items;
    }
    $res = $mysqli->query("SELECT id, mesa, nombre FROM supervisores_ventas ORDER BY mesa ASC, nombre ASC");
    if (!$res) {
        return $items;
    }
    while ($row = $res->fetch_assoc()) {
        $mesa = trim((string)($row['mesa'] ?? ''));
        $nombre = trim((string)($row['nombre'] ?? ''));
        $label = $nombre;
        if ($mesa !== '') {
            $label = $mesa . ' - ' . $nombre;
        }
        $items[] = [
            'id' => (int)$row['id'],
            'mesa' => $mesa,
            'nombre' => $nombre,
            'label' => $label,
        ];
    }
    $res->close();
    return $items;
}

function foco_fetch_vendedores(mysqli $mysqli): array {
    $items = [];
    if (!foco_table_exists($mysqli, 'vendedores')) {
        return $items;
    }
    $hasSupervisor = isset(foco_get_columns($mysqli, 'vendedores')['id_supervisor']);
    $sql = $hasSupervisor
        ? "SELECT v.codigo, v.nombre, v.id_supervisor, s.mesa, s.nombre AS supervisor_nombre
           FROM vendedores v
           LEFT JOIN supervisores_ventas s ON s.id = v.id_supervisor
           ORDER BY CAST(v.codigo AS UNSIGNED) ASC, v.codigo ASC"
        : "SELECT v.codigo, v.nombre, NULL AS id_supervisor, '' AS mesa, '' AS supervisor_nombre
           FROM vendedores v
           ORDER BY CAST(v.codigo AS UNSIGNED) ASC, v.codigo ASC";
    $res = $mysqli->query($sql);
    if (!$res) {
        return $items;
    }
    while ($row = $res->fetch_assoc()) {
        $codigo = foco_normalize_vendor_code((string)$row['codigo']);
        if ($codigo === '') {
            continue;
        }
        $items[] = [
            'codigo' => $codigo,
            'nombre' => trim((string)($row['nombre'] ?? '')),
            'supervisor_id' => isset($row['id_supervisor']) && $row['id_supervisor'] !== null ? (int)$row['id_supervisor'] : null,
            'mesa' => trim((string)($row['mesa'] ?? '')),
            'supervisor_nombre' => trim((string)($row['supervisor_nombre'] ?? '')),
        ];
    }
    $res->close();
    return $items;
}

function foco_fetch_productos(mysqli $mysqli): array {
    $items = [];
    $res = $mysqli->query("SELECT id, codigo_producto, nombre_producto, fecha_inicio, fecha_fin, activo, observacion FROM productos_foco ORDER BY activo DESC, codigo_producto ASC, nombre_producto ASC");
    if (!$res) {
        return $items;
    }
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $res->close();
    return $items;
}

function foco_lookup_catalog_product(mysqli $mysqli, string $codigo): ?array {
    $codigo = trim($codigo);
    if ($codigo === '' || !foco_table_exists($mysqli, 'codigo_productos')) {
        return null;
    }
    $stmt = $mysqli->prepare('SELECT codigo, producto FROM codigo_productos WHERE codigo = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'codigo' => trim((string)($row['codigo'] ?? '')),
        'nombre' => trim((string)($row['producto'] ?? '')),
    ];
}

function foco_fetch_productos_activos(mysqli $mysqli, string $startDate, string $endDate): array {
    $sql = "SELECT id, codigo_producto, nombre_producto, fecha_inicio, fecha_fin, activo, observacion
            FROM productos_foco
            WHERE activo = 1
              AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00' OR fecha_inicio <= ?)
              AND (fecha_fin IS NULL OR fecha_fin = '0000-00-00' OR fecha_fin >= ?)
            ORDER BY codigo_producto ASC, nombre_producto ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $endDate, $startDate);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $items;
}

function foco_fetch_meta_rows(mysqli $mysqli, array $productIds, string $startDate, string $endDate): array {
    if (!$productIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $types = str_repeat('i', count($productIds)) . 'ss';
    $sql = "SELECT id, producto_foco_id, meta_cantidad, fecha_inicio, fecha_fin, cod_vendedor, supervisor_id, activo, observacion
            FROM productos_foco_meta
            WHERE activo = 1
              AND producto_foco_id IN ({$placeholders})
              AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00' OR fecha_inicio <= ?)
              AND (fecha_fin IS NULL OR fecha_fin = '0000-00-00' OR fecha_fin >= ?)
            ORDER BY producto_foco_id ASC, fecha_inicio ASC, id ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $params = $productIds;
    $params[] = $endDate;
    $params[] = $startDate;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function foco_resolve_meta_for_vendor(array $metaRowsByProduct, int $productId, string $vendorCode, ?int $supervisorId): float {
    $rows = $metaRowsByProduct[$productId] ?? [];
    if (!$rows) {
        return 0.0;
    }
    $vendorCode = foco_normalize_vendor_code($vendorCode);
    $vendorRows = [];
    $supervisorRows = [];
    $globalRows = [];
    foreach ($rows as $row) {
        $rowVendor = foco_normalize_vendor_code((string)($row['cod_vendedor'] ?? ''));
        $rowSupervisor = isset($row['supervisor_id']) && $row['supervisor_id'] !== null ? (int)$row['supervisor_id'] : null;
        if ($rowVendor !== '') {
            if ($rowVendor === $vendorCode) {
                $vendorRows[] = $row;
            }
            continue;
        }
        if ($rowSupervisor !== null && $rowSupervisor > 0) {
            if ($supervisorId !== null && $rowSupervisor === (int)$supervisorId) {
                $supervisorRows[] = $row;
            }
            continue;
        }
        $globalRows[] = $row;
    }
    $bucket = $vendorRows ?: ($supervisorRows ?: $globalRows);
    $sum = 0.0;
    foreach ($bucket as $row) {
        $sum += (float)$row['meta_cantidad'];
    }
    return $sum;
}

function foco_render_products_table(array $rows): string {
    ob_start();
    echo '<table class="alm-foco-table"><thead><tr><th>Código</th><th>Nombre</th><th>Desde</th><th>Hasta</th><th>Activo</th><th>Observación</th><th>Acciones</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="7">No hay productos foco registrados.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . foco_h($row['codigo_producto']) . '</td>';
            echo '<td>' . foco_h($row['nombre_producto']) . '</td>';
            echo '<td>' . foco_h($row['fecha_inicio'] ?: '-') . '</td>';
            echo '<td>' . foco_h($row['fecha_fin'] ?: '-') . '</td>';
            echo '<td>' . ((int)$row['activo'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . foco_h($row['observacion'] ?: '-') . '</td>';
            echo '<td style="white-space:nowrap">'
                . '<button type="button" class="alm-foco-product-edit"'
                . ' data-id="' . (int)$row['id'] . '"'
                . ' data-codigo="' . foco_h($row['codigo_producto']) . '"'
                . ' data-nombre="' . foco_h($row['nombre_producto']) . '"'
                . ' data-desde="' . foco_h($row['fecha_inicio'] ?: '') . '"'
                . ' data-hasta="' . foco_h($row['fecha_fin'] ?: '') . '"'
                . ' data-activo="' . (int)$row['activo'] . '"'
                . ' data-observacion="' . foco_h($row['observacion'] ?: '') . '">Editar</button> '
                . '<button type="button" class="alm-foco-product-delete" data-id="' . (int)$row['id'] . '" style="background:#dc3545;">Eliminar</button>'
                . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
}

function foco_render_meta_table(array $rows): string {
    ob_start();
    echo '<table class="alm-foco-table"><thead><tr><th>Producto foco</th><th>Meta</th><th>Desde</th><th>Hasta</th><th>Vendedor</th><th>Supervisor</th><th>Activo</th><th>Observación</th><th>Acciones</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="9">No hay metas registradas.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $vendorLabel = trim((string)($row['cod_vendedor'] ?? '')) !== ''
                ? trim((string)$row['cod_vendedor']) . ' - ' . trim((string)($row['vendor_name'] ?? ''))
                : 'Global';
            $supervisorLabel = trim((string)($row['supervisor_label'] ?? '')) !== '' ? trim((string)$row['supervisor_label']) : 'Todos';
            echo '<tr>';
            echo '<td>' . foco_h(($row['codigo_producto'] ?? '') . ' - ' . ($row['nombre_producto'] ?? '')) . '</td>';
            echo '<td>' . number_format((float)$row['meta_cantidad'], 2, '.', ',') . '</td>';
            echo '<td>' . foco_h($row['fecha_inicio'] ?: '-') . '</td>';
            echo '<td>' . foco_h($row['fecha_fin'] ?: '-') . '</td>';
            echo '<td>' . foco_h($vendorLabel) . '</td>';
            echo '<td>' . foco_h($supervisorLabel) . '</td>';
            echo '<td>' . ((int)$row['activo'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . foco_h($row['observacion'] ?: '-') . '</td>';
            echo '<td style="white-space:nowrap">'
                . '<button type="button" class="alm-foco-meta-edit"'
                . ' data-id="' . (int)$row['id'] . '"'
                . ' data-producto-id="' . (int)$row['producto_foco_id'] . '"'
                . ' data-meta="' . foco_h((string)$row['meta_cantidad']) . '"'
                . ' data-desde="' . foco_h($row['fecha_inicio'] ?: '') . '"'
                . ' data-hasta="' . foco_h($row['fecha_fin'] ?: '') . '"'
                . ' data-vendedor="' . foco_h($row['cod_vendedor'] ?: '') . '"'
                . ' data-supervisor-id="' . (int)($row['supervisor_id'] ?? 0) . '"'
                . ' data-activo="' . (int)$row['activo'] . '"'
                . ' data-observacion="' . foco_h($row['observacion'] ?: '') . '">Editar</button> '
                . '<button type="button" class="alm-foco-meta-delete" data-id="' . (int)$row['id'] . '" style="background:#dc3545;">Eliminar</button>'
                . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
}

function foco_build_report(mysqli $mysqli, string $mode, string $selectedDate): string {
    $date = foco_parse_date($selectedDate) ?: date('Y-m-d');
    $mode = ($mode === 'month') ? 'month' : 'day';
    $endDate = $date;
    $startDate = ($mode === 'month') ? date('Y-m-01', strtotime($date)) : $date;

    $products = foco_fetch_productos_activos($mysqli, $startDate, $endDate);
    if (!$products) {
        return '<p>No hay productos foco activos para el rango seleccionado.</p>';
    }

    $productIds = [];
    $productCodes = [];
    $productsById = [];
    $productsByCode = [];
    foreach ($products as $product) {
        $productIds[] = (int)$product['id'];
        $code = trim((string)$product['codigo_producto']);
        $productCodes[] = $code;
        $productsById[(int)$product['id']] = $product;
        $productsByCode[$code] = $product;
    }

    $metaRows = foco_fetch_meta_rows($mysqli, $productIds, $startDate, $endDate);
    $metaRowsByProduct = [];
    foreach ($metaRows as $row) {
        $productId = (int)$row['producto_foco_id'];
        if (!isset($metaRowsByProduct[$productId])) {
            $metaRowsByProduct[$productId] = [];
        }
        $metaRowsByProduct[$productId][] = $row;
    }

    $vendors = foco_fetch_vendedores($mysqli);
    $vendorsByCode = [];
    foreach ($vendors as $vendor) {
        $vendorsByCode[$vendor['codigo']] = $vendor;
    }

    $placeholders = implode(',', array_fill(0, count($productCodes), '?'));
    $types = 'ss' . str_repeat('s', count($productCodes));
    $sql = "SELECT fecha, cod_vendedor, nom_vendedor, codigo, zona, cod_producto, cantidad
            FROM pedidos_x_dia_detallado
            WHERE fecha BETWEEN ? AND ?
              AND cod_producto IN ({$placeholders})
            ORDER BY fecha ASC, cod_vendedor ASC, cod_producto ASC";
    $stmt = $mysqli->prepare($sql);
    $params = array_merge([$startDate, $endDate], $productCodes);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $mesaData = [];
    $vendorData = [];
    $globalTotals = [];

    foreach ($rows as $row) {
        $productCode = trim((string)($row['cod_producto'] ?? ''));
        if ($productCode === '' || !isset($productsByCode[$productCode])) {
            continue;
        }
        $product = $productsByCode[$productCode];
        $productId = (int)$product['id'];
        $vendorCode = foco_normalize_vendor_code((string)($row['cod_vendedor'] ?? ''));
        $vendorInfo = $vendorsByCode[$vendorCode] ?? null;
        $mesa = trim((string)($vendorInfo['mesa'] ?? ''));
        if (!foco_is_allowed_mesa($mesa)) {
            continue;
        }
        $mesa = foco_normalize_mesa_label($mesa);
        $vendorName = trim((string)($row['nom_vendedor'] ?? ''));
        if ($vendorName === '' && $vendorInfo) {
            $vendorName = trim((string)$vendorInfo['nombre']);
        }
        $route = trim((string)($row['zona'] ?? ''));
        if ($route === '') {
            $route = '-';
        }
        $clientCode = trim((string)($row['codigo'] ?? ''));
        $quantity = (float)($row['cantidad'] ?? 0);
        $supervisorId = $vendorInfo['supervisor_id'] ?? null;
        $resolvedMeta = foco_resolve_meta_for_vendor($metaRowsByProduct, $productId, $vendorCode, $supervisorId);

        if (!isset($mesaData[$mesa])) {
            $mesaData[$mesa] = [
                'products' => [],
                'vendors' => [],
            ];
        }
        if (!isset($mesaData[$mesa]['products'][$productCode])) {
            $mesaData[$mesa]['products'][$productCode] = [
                'product' => $product,
                'qty' => 0.0,
                'clients' => [],
                'routes' => [],
                'meta' => 0.0,
            ];
        }
        if (!isset($mesaData[$mesa]['vendors'][$vendorCode])) {
            $mesaData[$mesa]['vendors'][$vendorCode] = [
                'vendor_code' => $vendorCode !== '' ? $vendorCode : '-',
                'vendor_name' => $vendorName !== '' ? $vendorName : '-',
                'supervisor_id' => $supervisorId,
                'products' => [],
            ];
        }
        if (!isset($mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode])) {
            $mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode] = [
                'product' => $product,
                'qty' => 0.0,
                'clients' => [],
                'meta' => $resolvedMeta,
            ];
        }
        if (!isset($mesaData[$mesa]['products'][$productCode]['routes'][$route])) {
            $mesaData[$mesa]['products'][$productCode]['routes'][$route] = [
                'qty' => 0.0,
                'clients' => [],
            ];
        }

        $mesaData[$mesa]['products'][$productCode]['qty'] += $quantity;
        if ($clientCode !== '') {
            $mesaData[$mesa]['products'][$productCode]['clients'][$clientCode] = true;
            $mesaData[$mesa]['products'][$productCode]['routes'][$route]['clients'][$clientCode] = true;
            $mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode]['clients'][$clientCode] = true;
        }
        $mesaData[$mesa]['products'][$productCode]['routes'][$route]['qty'] += $quantity;
        $mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode]['qty'] += $quantity;

        if (!isset($vendorData[$vendorCode])) {
            $vendorData[$vendorCode] = [
                'vendor_code' => $vendorCode !== '' ? $vendorCode : '-',
                'vendor_name' => $vendorName !== '' ? $vendorName : '-',
                'mesa' => $mesa,
                'products' => [],
            ];
        }
        if (!isset($vendorData[$vendorCode]['products'][$productCode])) {
            $vendorData[$vendorCode]['products'][$productCode] = [
                'product' => $product,
                'qty' => 0.0,
                'clients' => [],
                'meta' => $resolvedMeta,
            ];
        }
        $vendorData[$vendorCode]['products'][$productCode]['qty'] += $quantity;
        if ($clientCode !== '') {
            $vendorData[$vendorCode]['products'][$productCode]['clients'][$clientCode] = true;
        }

        if (!isset($globalTotals[$productCode])) {
            $globalTotals[$productCode] = [
                'product' => $product,
                'qty' => 0.0,
                'clients' => [],
                'meta' => 0.0,
            ];
        }
        $globalTotals[$productCode]['qty'] += $quantity;
        if ($clientCode !== '') {
            $globalTotals[$productCode]['clients'][$clientCode] = true;
        }
    }

    foreach ($vendors as $vendor) {
        $vendorCode = $vendor['codigo'];
        $mesa = foco_normalize_mesa_label($vendor['mesa'] ?? '');
        if (!foco_is_allowed_mesa($mesa)) {
            continue;
        }
        if (!isset($mesaData[$mesa])) {
            $mesaData[$mesa] = ['products' => [], 'vendors' => []];
        }
        if (!isset($mesaData[$mesa]['vendors'][$vendorCode])) {
            $mesaData[$mesa]['vendors'][$vendorCode] = [
                'vendor_code' => $vendorCode,
                'vendor_name' => $vendor['nombre'] !== '' ? $vendor['nombre'] : '-',
                'supervisor_id' => $vendor['supervisor_id'],
                'products' => [],
            ];
        }
        if (!isset($vendorData[$vendorCode])) {
            $vendorData[$vendorCode] = [
                'vendor_code' => $vendorCode,
                'vendor_name' => $vendor['nombre'] !== '' ? $vendor['nombre'] : '-',
                'mesa' => $mesa,
                'products' => [],
            ];
        }
        foreach ($products as $product) {
            $productCode = (string)$product['codigo_producto'];
            $productId = (int)$product['id'];
            if (!isset($mesaData[$mesa]['products'][$productCode])) {
                $mesaData[$mesa]['products'][$productCode] = [
                    'product' => $product,
                    'qty' => 0.0,
                    'clients' => [],
                    'routes' => [],
                    'meta' => 0.0,
                ];
            }
            $resolvedMeta = foco_resolve_meta_for_vendor($metaRowsByProduct, $productId, $vendorCode, $vendor['supervisor_id']);
            $mesaData[$mesa]['products'][$productCode]['meta'] += $resolvedMeta;
            if (!isset($mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode])) {
                $mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode] = [
                    'product' => $product,
                    'qty' => 0.0,
                    'clients' => [],
                    'meta' => $resolvedMeta,
                ];
            } else {
                $mesaData[$mesa]['vendors'][$vendorCode]['products'][$productCode]['meta'] = $resolvedMeta;
            }
            if (!isset($vendorData[$vendorCode]['products'][$productCode])) {
                $vendorData[$vendorCode]['products'][$productCode] = [
                    'product' => $product,
                    'qty' => 0.0,
                    'clients' => [],
                    'meta' => $resolvedMeta,
                ];
            } else {
                $vendorData[$vendorCode]['products'][$productCode]['meta'] = $resolvedMeta;
            }
            if (!isset($globalTotals[$productCode])) {
                $globalTotals[$productCode] = [
                    'product' => $product,
                    'qty' => 0.0,
                    'clients' => [],
                    'meta' => 0.0,
                ];
            }
            $globalTotals[$productCode]['meta'] += $resolvedMeta;
        }
    }

    ksort($mesaData, SORT_NATURAL);
    ksort($vendorData, SORT_NATURAL);
    uksort($globalTotals, 'strnatcmp');

    $title = $mode === 'month'
        ? 'Acumulado del mes al ' . date('d/m/Y', strtotime($endDate))
        : 'Avance del día ' . date('d/m/Y', strtotime($date));

    ob_start();
    echo '<div class="alm-foco-report">';
    echo '<div class="alm-foco-report-head">';
    echo '<div><strong>' . foco_h($title) . '</strong><div class="alm-foco-report-sub">Rango: ' . foco_h($startDate) . ' a ' . foco_h($endDate) . '</div></div>';
    echo '<button type="button" onclick="window.print()">Imprimir</button>';
    echo '</div>';

    $displayProductTotals = [];
    foreach ($mesaData as $mesa => $mesaBucket) {
        foreach ($mesaBucket['products'] as $productCode => $bucket) {
            if (!isset($displayProductTotals[$productCode])) {
                $displayProductTotals[$productCode] = [
                    'product' => $bucket['product'],
                    'qty' => 0.0,
                    'clients' => [],
                    'meta' => 0.0,
                ];
            }
            $displayProductTotals[$productCode]['qty'] += (float)$bucket['qty'];
            $displayProductTotals[$productCode]['meta'] += (float)$bucket['meta'];
            foreach (array_keys($bucket['clients']) as $clientCode) {
                $displayProductTotals[$productCode]['clients'][$clientCode] = true;
            }
        }
    }
    uksort($displayProductTotals, 'strnatcmp');

    echo '<div class="alm-foco-global-grid">';
    foreach ($displayProductTotals as $productCode => $bucket) {
        $clients = count($bucket['clients']);
        $qty = (float)$bucket['qty'];
        $meta = (float)$bucket['meta'];
        $avance = $meta > 0 ? (($qty / $meta) * 100) : 0.0;
        echo '<article class="alm-foco-global-card">';
        echo '<h4>' . foco_h($productCode) . '</h4>';
        echo '<div class="alm-foco-global-name">' . foco_h($bucket['product']['nombre_producto']) . '</div>';
        echo '<div class="alm-foco-global-stats">';
        echo '<span>Clientes <strong>' . number_format($clients, 0, '.', ',') . '</strong></span>';
        echo '<span>Cantidad <strong>' . number_format($qty, 2, '.', ',') . '</strong></span>';
        echo '<span>Meta <strong>' . number_format($meta, 2, '.', ',') . '</strong></span>';
        echo '<span>Avance <strong>' . number_format($avance, 1, '.', ',') . '%</strong></span>';
        echo '</div>';
        echo '</article>';
    }
    echo '</div>';

    $mesaNames = array_keys($mesaData);
    natsort($mesaNames);
    echo '<div class="alm-foco-products-stack">';
    foreach ($displayProductTotals as $productCode => $bucket) {
        $product = $bucket['product'];
        $globalClients = count($bucket['clients']);
        $globalQty = (float)$bucket['qty'];
        $globalMeta = (float)$bucket['meta'];
        $globalAvance = $globalMeta > 0 ? (($globalQty / $globalMeta) * 100) : 0.0;
        if ($globalQty <= 0 && $globalMeta <= 0) {
            continue;
        }
        echo '<section class="alm-foco-product-block">';
        echo '<div class="alm-foco-product-block-head">';
        echo '<div>';
        echo '<div class="alm-foco-product-code">' . foco_h($productCode) . '</div>';
        echo '<div class="alm-foco-product-name">' . foco_h($product['nombre_producto']) . '</div>';
        echo '</div>';
        echo '<div class="alm-foco-product-kpis alm-foco-product-kpis-global">';
        echo '<span><b>Clientes</b><strong>' . number_format($globalClients, 0, '.', ',') . '</strong></span>';
        echo '<span><b>Cantidad</b><strong>' . number_format($globalQty, 2, '.', ',') . '</strong></span>';
        echo '<span><b>Meta</b><strong>' . number_format($globalMeta, 2, '.', ',') . '</strong></span>';
        echo '<span><b>Avance</b><strong>' . number_format($globalAvance, 1, '.', ',') . '%</strong></span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="alm-foco-mesas-grid">';
        foreach ($mesaNames as $mesa) {
            $mesaBucket = $mesaData[$mesa];
            $productBucket = $mesaBucket['products'][$productCode] ?? null;
            if (!$productBucket) {
                continue;
            }
            $clients = count($productBucket['clients']);
            $qty = (float)$productBucket['qty'];
            $meta = (float)$productBucket['meta'];
            $avance = $meta > 0 ? (($qty / $meta) * 100) : 0.0;
            $vendorRows = [];
            foreach ($mesaBucket['vendors'] as $vendorBucket) {
                $vendorProductBucket = $vendorBucket['products'][$productCode] ?? null;
                if (!$vendorProductBucket) {
                    continue;
                }
                $vendorRows[] = [
                    'label' => trim((string)$vendorBucket['vendor_code']) !== '' ? trim((string)$vendorBucket['vendor_code']) : '-',
                    'clients' => count($vendorProductBucket['clients']),
                    'qty' => (float)$vendorProductBucket['qty'],
                ];
            }
            usort($vendorRows, function(array $left, array $right): int {
                return strnatcmp($left['label'], $right['label']);
            });
            echo '<article class="alm-foco-mesa-block">';
            echo '<div class="alm-foco-mesa-title">Mesa ' . foco_h($mesa) . '</div>';
            echo '<div class="alm-foco-product-card">';
            echo '<div class="alm-foco-product-kpis">';
            echo '<span><b>Clientes</b><strong>' . number_format($clients, 0, '.', ',') . '</strong></span>';
            echo '<span><b>Cantidad</b><strong>' . number_format($qty, 2, '.', ',') . '</strong></span>';
            echo '<span><b>Meta</b><strong>' . number_format($meta, 2, '.', ',') . '</strong></span>';
            echo '<span><b>Avance</b><strong>' . number_format($avance, 1, '.', ',') . '%</strong></span>';
            echo '</div>';
            echo '<table class="alm-foco-mini-table"><thead><tr><th>VD</th><th>Cliente</th><th>Cantidad</th></tr></thead><tbody>';
            foreach ($vendorRows as $vendorRow) {
                echo '<tr>';
                echo '<td>' . foco_h($vendorRow['label']) . '</td>';
                echo '<td>' . number_format($vendorRow['clients'], 0, '.', ',') . '</td>';
                echo '<td>' . number_format((float)$vendorRow['qty'], 2, '.', ',') . '</td>';
                echo '</tr>';
            }
            echo '</tbody><tfoot><tr><th>Total</th><th>' . number_format($clients, 0, '.', ',') . '</th><th>' . number_format($qty, 2, '.', ',') . '</th></tr></tfoot></table>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
        echo '</section>';
    }
    echo '</div>';

    echo '</div>';
    return (string)ob_get_clean();
}

foco_ensure_tables($mysqli);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'products_list');

if ($action === 'options') {
    foco_json([
        'ok' => true,
        'productos' => array_map(function(array $row): array {
            return [
                'id' => (int)$row['id'],
                'codigo' => (string)$row['codigo_producto'],
                'nombre' => (string)$row['nombre_producto'],
                'activo' => (int)$row['activo'] === 1,
            ];
        }, foco_fetch_productos($mysqli)),
        'vendedores' => foco_fetch_vendedores($mysqli),
        'supervisores' => foco_fetch_supervisores($mysqli),
    ]);
}

if ($action === 'products_list') {
    header('Content-Type: text/html; charset=utf-8');
    echo foco_render_products_table(foco_fetch_productos($mysqli));
    exit;
}

if ($action === 'product_lookup') {
    $codigo = trim((string)($_GET['codigo'] ?? ''));
    if ($codigo === '') {
        foco_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    $product = foco_lookup_catalog_product($mysqli, $codigo);
    if (!$product || $product['nombre'] === '') {
        foco_json(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }
    foco_json(['ok' => true, 'product' => $product]);
}

if ($action === 'product_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $codigo = trim((string)($_POST['codigo_producto'] ?? ''));
    $nombre = trim((string)($_POST['nombre_producto'] ?? ''));
    $fechaInicio = foco_parse_date($_POST['fecha_inicio'] ?? '');
    $fechaFin = foco_parse_date($_POST['fecha_fin'] ?? '');
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    $catalogProduct = foco_lookup_catalog_product($mysqli, $codigo);
    if ($codigo === '' || !$catalogProduct || $catalogProduct['nombre'] === '') {
        foco_json(['ok' => false, 'error' => 'REQUIRED'], 400);
    }
    $codigo = $catalogProduct['codigo'];
    $nombre = $catalogProduct['nombre'];
    if ($fechaInicio !== null && $fechaFin !== null && $fechaFin < $fechaInicio) {
        foco_json(['ok' => false, 'error' => 'INVALID_RANGE'], 400);
    }
    if ($id > 0) {
        $stmt = $mysqli->prepare('UPDATE productos_foco SET codigo_producto=?, nombre_producto=?, fecha_inicio=?, fecha_fin=?, activo=?, observacion=? WHERE id=?');
        if (!$stmt) {
            foco_json(['ok' => false, 'error' => 'DB'], 500);
        }
        $stmt->bind_param('ssssisi', $codigo, $nombre, $fechaInicio, $fechaFin, $activo, $observacion, $id);
        $ok = $stmt->execute();
        $stmt->close();
        foco_json(['ok' => $ok ? true : false]);
    }
    $stmt = $mysqli->prepare('INSERT INTO productos_foco (codigo_producto, nombre_producto, fecha_inicio, fecha_fin, activo, observacion) VALUES (?,?,?,?,?,?)');
    if (!$stmt) {
        foco_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('ssssis', $codigo, $nombre, $fechaInicio, $fechaFin, $activo, $observacion);
    $ok = $stmt->execute();
    $stmt->close();
    foco_json(['ok' => $ok ? true : false]);
}

if ($action === 'product_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        foco_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    $stmt = $mysqli->prepare('DELETE FROM productos_foco WHERE id=?');
    if (!$stmt) {
        foco_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    foco_json(['ok' => $ok ? true : false]);
}

if ($action === 'metas_list') {
    $sql = "SELECT m.id, m.producto_foco_id, m.meta_cantidad, m.fecha_inicio, m.fecha_fin, m.cod_vendedor,
                   m.supervisor_id, m.activo, m.observacion,
                   p.codigo_producto, p.nombre_producto,
                   v.nombre AS vendor_name,
                   s.mesa, s.nombre AS supervisor_nombre
            FROM productos_foco_meta m
            INNER JOIN productos_foco p ON p.id = m.producto_foco_id
            LEFT JOIN vendedores v ON v.codigo = m.cod_vendedor
            LEFT JOIN supervisores_ventas s ON s.id = m.supervisor_id
            ORDER BY m.activo DESC, p.codigo_producto ASC, m.fecha_inicio DESC, m.id DESC";
    $res = $mysqli->query($sql);
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $mesa = trim((string)($row['mesa'] ?? ''));
        $supNombre = trim((string)($row['supervisor_nombre'] ?? ''));
        $row['supervisor_label'] = $mesa !== '' ? ($mesa . ' - ' . $supNombre) : $supNombre;
        $rows[] = $row;
    }
    if ($res) {
        $res->close();
    }
    header('Content-Type: text/html; charset=utf-8');
    echo foco_render_meta_table($rows);
    exit;
}

if ($action === 'meta_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $productoId = (int)($_POST['producto_foco_id'] ?? 0);
    $metaCantidad = (float)($_POST['meta_cantidad'] ?? 0);
    $fechaInicio = foco_parse_date($_POST['fecha_inicio'] ?? '');
    $fechaFin = foco_parse_date($_POST['fecha_fin'] ?? '');
    $codVendedor = foco_normalize_vendor_code($_POST['cod_vendedor'] ?? '');
    $supervisorId = isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '' ? (int)$_POST['supervisor_id'] : null;
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    if ($productoId <= 0 || $metaCantidad <= 0) {
        foco_json(['ok' => false, 'error' => 'REQUIRED'], 400);
    }
    if ($fechaInicio !== null && $fechaFin !== null && $fechaFin < $fechaInicio) {
        foco_json(['ok' => false, 'error' => 'INVALID_RANGE'], 400);
    }
    $codVendedorOrNull = ($codVendedor !== '') ? $codVendedor : null;
    $supervisorIdOrNull = ($supervisorId !== null && $supervisorId > 0) ? $supervisorId : null;
    if ($id > 0) {
        $stmt = $mysqli->prepare('UPDATE productos_foco_meta SET producto_foco_id=?, meta_cantidad=?, fecha_inicio=?, fecha_fin=?, cod_vendedor=?, supervisor_id=?, activo=?, observacion=? WHERE id=?');
        if (!$stmt) {
            foco_json(['ok' => false, 'error' => 'DB'], 500);
        }
        $stmt->bind_param('idsssiisi', $productoId, $metaCantidad, $fechaInicio, $fechaFin, $codVendedorOrNull, $supervisorIdOrNull, $activo, $observacion, $id);
        $ok = $stmt->execute();
        $stmt->close();
        foco_json(['ok' => $ok ? true : false]);
    }
    $stmt = $mysqli->prepare('INSERT INTO productos_foco_meta (producto_foco_id, meta_cantidad, fecha_inicio, fecha_fin, cod_vendedor, supervisor_id, activo, observacion) VALUES (?,?,?,?,?,?,?,?)');
    if (!$stmt) {
        foco_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('idsssiis', $productoId, $metaCantidad, $fechaInicio, $fechaFin, $codVendedorOrNull, $supervisorIdOrNull, $activo, $observacion);
    $ok = $stmt->execute();
    $stmt->close();
    foco_json(['ok' => $ok ? true : false]);
}

if ($action === 'meta_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        foco_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    $stmt = $mysqli->prepare('DELETE FROM productos_foco_meta WHERE id=?');
    if (!$stmt) {
        foco_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    foco_json(['ok' => $ok ? true : false]);
}

if ($action === 'avance') {
    $mode = (string)($_GET['mode'] ?? 'day');
    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    header('Content-Type: text/html; charset=utf-8');
    echo foco_build_report($mysqli, $mode, $date);
    exit;
}

http_response_code(400);
echo 'Acción no válida';
