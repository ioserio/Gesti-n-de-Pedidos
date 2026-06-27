<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

function cobertura_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cobertura_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function cobertura_parse_date($value): ?string {
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

function cobertura_normalize_vendor_code($code): string {
    $digits = preg_replace('/\D+/', '', trim((string)$code));
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (strlen($digits) > 3) {
        $digits = substr($digits, -3);
    }
    return str_pad($digits, 3, '0', STR_PAD_LEFT);
}

function cobertura_normalize_product_code($code): string {
    $digits = preg_replace('/\D+/', '', trim((string)$code));
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (strlen($digits) >= 4) {
        return $digits;
    }
    return str_pad($digits, 4, '0', STR_PAD_LEFT);
}

function cobertura_normalize_abreviacion($value): string {
    return strtoupper(trim((string)$value));
}

function cobertura_table_exists(mysqli $mysqli, string $table): bool {
    $safe = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
}

function cobertura_get_columns(mysqli $mysqli, string $table): array {
    $cols = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
        $res->close();
    }
    return $cols;
}

function cobertura_normalize_code($code): string {
    return cobertura_normalize_product_code($code);
}

function cobertura_parse_codes($raw): array {
    $raw = str_replace(["\r\n", "\r", ';', '|', "\t"], ["\n", "\n", ',', ',', ','], (string)$raw);
    $raw = preg_replace('/\s+/', ',', $raw);
    $parts = preg_split('/,+/', (string)$raw);
    $codes = [];
    foreach ($parts as $part) {
        $code = cobertura_normalize_code($part);
        if ($code === '') {
            continue;
        }
        $codes[$code] = true;
    }
    return array_keys($codes);
}

function cobertura_fetch_supervisores(mysqli $mysqli): array {
    $items = [];
    if (!cobertura_table_exists($mysqli, 'supervisores_ventas')) {
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

function cobertura_fetch_vendedores(mysqli $mysqli): array {
    $items = [];
    if (!cobertura_table_exists($mysqli, 'vendedores')) {
        return $items;
    }
    $hasSupervisor = isset(cobertura_get_columns($mysqli, 'vendedores')['id_supervisor']);
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
        $codigo = cobertura_normalize_vendor_code((string)$row['codigo']);
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

function cobertura_lookup_product_names(mysqli $mysqli, array $codes): array {
    $names = [];
    if (!$codes || !cobertura_table_exists($mysqli, 'codigo_productos')) {
        return $names;
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $sql = "SELECT codigo, producto FROM codigo_productos WHERE codigo IN ({$placeholders})";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return $names;
    }
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res ? $res->fetch_assoc() : null) {
        $normalizedCode = cobertura_normalize_product_code((string)$row['codigo']);
        if ($normalizedCode === '') {
            continue;
        }
        $names[$normalizedCode] = trim((string)($row['producto'] ?? ''));
    }
    $stmt->close();
    return $names;
}

function cobertura_ensure_tables(mysqli $mysqli): void {
    $mysqli->query("CREATE TABLE IF NOT EXISTS cobertura_grupos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_grupo VARCHAR(160) NOT NULL,
        meta_unidades DECIMAL(15,2) NOT NULL DEFAULT 0,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observacion VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cobertura_grupo_fechas (fecha_inicio, fecha_fin),
        KEY idx_cobertura_grupo_activo (activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $mysqli->query("CREATE TABLE IF NOT EXISTS cobertura_grupo_codigos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grupo_id INT NOT NULL,
        codigo_producto VARCHAR(50) NOT NULL,
        nombre_producto VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cobertura_grupo_codigo (grupo_id, codigo_producto),
        KEY idx_cobertura_codigo (codigo_producto),
        KEY idx_cobertura_grupo (grupo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $mysqli->query("CREATE TABLE IF NOT EXISTS cobertura_grupo_metas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grupo_id INT NOT NULL,
        cod_vendedor VARCHAR(20) NOT NULL,
        meta_unidades DECIMAL(15,2) NOT NULL DEFAULT 0,
        fecha_inicio DATE DEFAULT NULL,
        fecha_fin DATE DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        observacion VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cobertura_meta_grupo (grupo_id),
        KEY idx_cobertura_meta_vendedor (cod_vendedor),
        KEY idx_cobertura_meta_fechas (fecha_inicio, fecha_fin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $cols = cobertura_get_columns($mysqli, 'cobertura_grupo_codigos');
        if (!isset($cols['nombre_producto'])) {
            @$mysqli->query("ALTER TABLE cobertura_grupo_codigos ADD COLUMN nombre_producto VARCHAR(255) DEFAULT NULL AFTER codigo_producto");
        }
    } catch (Throwable $e) {
    }
}

function cobertura_fetch_groups(mysqli $mysqli): array {
    $items = [];
    $sql = "SELECT g.id, g.nombre_grupo, g.meta_unidades, g.fecha_inicio, g.fecha_fin, g.activo, g.observacion,
                         COALESCE((SELECT SUM(m.meta_unidades) FROM cobertura_grupo_metas m WHERE m.grupo_id = g.id AND m.activo = 1), 0) AS meta_total,
                         COALESCE((SELECT COUNT(*) FROM cobertura_grupo_codigos c2 WHERE c2.grupo_id = g.id), 0) AS total_codigos,
                         (SELECT GROUP_CONCAT(c3.codigo_producto ORDER BY c3.codigo_producto ASC SEPARATOR ', ')
                             FROM cobertura_grupo_codigos c3 WHERE c3.grupo_id = g.id) AS codigos,
                         (SELECT GROUP_CONCAT(CONCAT(c4.codigo_producto, IF(c4.nombre_producto IS NULL OR c4.nombre_producto = '', '', CONCAT(' - ', c4.nombre_producto))) ORDER BY c4.codigo_producto ASC SEPARATOR '\n')
                             FROM cobertura_grupo_codigos c4 WHERE c4.grupo_id = g.id) AS codigos_detalle
                FROM cobertura_grupos g
            ORDER BY g.activo DESC, g.fecha_inicio DESC, g.nombre_grupo ASC";
    $res = $mysqli->query($sql);
    if (!$res) {
        return $items;
    }
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $res->close();
    return $items;
}

function cobertura_fetch_active_groups(mysqli $mysqli, string $startDate, string $endDate): array {
    $sql = "SELECT id, nombre_grupo, meta_unidades, fecha_inicio, fecha_fin, activo, observacion
            FROM cobertura_grupos
            WHERE activo = 1
              AND fecha_inicio <= ?
              AND fecha_fin >= ?
            ORDER BY nombre_grupo ASC";
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

function cobertura_fetch_group_codes(mysqli $mysqli, array $groupIds): array {
    if (!$groupIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types = str_repeat('i', count($groupIds));
    $sql = "SELECT grupo_id, codigo_producto, nombre_producto
            FROM cobertura_grupo_codigos
            WHERE grupo_id IN ({$placeholders})
            ORDER BY grupo_id ASC, codigo_producto ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$groupIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function cobertura_render_groups_table(array $rows): string {
    ob_start();
    echo '<table class="alm-foco-table"><thead><tr><th>Grupo</th><th>Meta total</th><th>Desde</th><th>Hasta</th><th>Códigos</th><th>Activo</th><th>Observación</th><th>Acciones</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="8">No hay grupos de cobertura registrados.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . cobertura_h($row['nombre_grupo']) . '</td>';
            $metaTotal = (float)($row['meta_total'] ?? 0);
            if ($metaTotal <= 0 && (float)$row['meta_unidades'] > 0) {
                $metaTotal = (float)$row['meta_unidades'];
            }
            echo '<td>' . number_format($metaTotal, 2, '.', ',') . '</td>';
            echo '<td>' . cobertura_h($row['fecha_inicio']) . '</td>';
            echo '<td>' . cobertura_h($row['fecha_fin']) . '</td>';
            echo '<td><div class="alm-cobertura-codes">' . nl2br(cobertura_h($row['codigos_detalle'] ?: ($row['codigos'] ?: '-'))) . '</div></td>';
            echo '<td>' . ((int)$row['activo'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . cobertura_h($row['observacion'] ?: '-') . '</td>';
            echo '<td style="white-space:nowrap">'
                . '<button type="button" class="alm-cobertura-group-edit"'
                . ' data-id="' . (int)$row['id'] . '"'
                . ' data-nombre="' . cobertura_h($row['nombre_grupo']) . '"'
                . ' data-desde="' . cobertura_h($row['fecha_inicio']) . '"'
                . ' data-hasta="' . cobertura_h($row['fecha_fin']) . '"'
                . ' data-codigos="' . cobertura_h($row['codigos'] ?: '') . '"'
                . ' data-activo="' . (int)$row['activo'] . '"'
                . ' data-observacion="' . cobertura_h($row['observacion'] ?: '') . '">Editar</button> '
                . '<button type="button" class="alm-cobertura-group-delete" data-id="' . (int)$row['id'] . '" style="background:#dc3545;">Eliminar</button>'
                . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
}

function cobertura_fetch_meta_rows(mysqli $mysqli): array {
    $sql = "SELECT m.id, m.grupo_id, m.cod_vendedor, m.meta_unidades, m.fecha_inicio, m.fecha_fin, m.activo, m.observacion,
                   g.nombre_grupo,
                   v.nombre AS vendor_name,
                   s.id AS supervisor_id,
                   s.mesa,
                   s.nombre AS supervisor_nombre
            FROM cobertura_grupo_metas m
            INNER JOIN cobertura_grupos g ON g.id = m.grupo_id
            LEFT JOIN vendedores v ON v.codigo = m.cod_vendedor
            LEFT JOIN supervisores_ventas s ON s.id = v.id_supervisor
            ORDER BY m.activo DESC, g.nombre_grupo ASC, m.fecha_inicio DESC, m.id DESC";
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
    return $rows;
}

function cobertura_render_meta_table(array $rows): string {
    ob_start();
    echo '<table class="alm-foco-table"><thead><tr><th>Grupo</th><th>Vendedor</th><th>Meta</th><th>Desde</th><th>Hasta</th><th>Supervisor</th><th>Activo</th><th>Observación</th><th>Acciones</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="9">No hay metas de cobertura registradas.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $vendorLabel = trim((string)($row['cod_vendedor'] ?? '')) . ' - ' . trim((string)($row['vendor_name'] ?? ''));
            echo '<tr>';
            echo '<td>' . cobertura_h($row['nombre_grupo']) . '</td>';
            echo '<td>' . cobertura_h($vendorLabel) . '</td>';
            echo '<td>' . number_format((float)$row['meta_unidades'], 2, '.', ',') . '</td>';
            echo '<td>' . cobertura_h($row['fecha_inicio'] ?: '-') . '</td>';
            echo '<td>' . cobertura_h($row['fecha_fin'] ?: '-') . '</td>';
            echo '<td>' . cobertura_h($row['supervisor_label'] ?: '-') . '</td>';
            echo '<td>' . ((int)$row['activo'] === 1 ? 'Sí' : 'No') . '</td>';
            echo '<td>' . cobertura_h($row['observacion'] ?: '-') . '</td>';
            echo '<td style="white-space:nowrap">'
                . '<button type="button" class="alm-cobertura-meta-edit"'
                . ' data-id="' . (int)$row['id'] . '"'
                . ' data-grupo-id="' . (int)$row['grupo_id'] . '"'
                . ' data-vendedor="' . cobertura_h($row['cod_vendedor']) . '"'
                . ' data-supervisor-id="' . (int)($row['supervisor_id'] ?? 0) . '"'
                . ' data-meta="' . cobertura_h((string)$row['meta_unidades']) . '"'
                . ' data-desde="' . cobertura_h($row['fecha_inicio'] ?: '') . '"'
                . ' data-hasta="' . cobertura_h($row['fecha_fin'] ?: '') . '"'
                . ' data-activo="' . (int)$row['activo'] . '"'
                . ' data-observacion="' . cobertura_h($row['observacion'] ?: '') . '">Editar</button> '
                . '<button type="button" class="alm-cobertura-meta-delete" data-id="' . (int)$row['id'] . '" style="background:#dc3545;">Eliminar</button>'
                . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
}

function cobertura_fetch_active_meta_rows(mysqli $mysqli, array $groupIds, string $startDate, string $endDate): array {
    if (!$groupIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types = str_repeat('i', count($groupIds)) . 'ss';
    $sql = "SELECT id, grupo_id, cod_vendedor, meta_unidades, fecha_inicio, fecha_fin, activo, observacion
            FROM cobertura_grupo_metas
            WHERE activo = 1
              AND grupo_id IN ({$placeholders})
              AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00' OR fecha_inicio <= ?)
              AND (fecha_fin IS NULL OR fecha_fin = '0000-00-00' OR fecha_fin >= ?)
            ORDER BY grupo_id ASC, cod_vendedor ASC, fecha_inicio ASC, id ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $params = $groupIds;
    $params[] = $endDate;
    $params[] = $startDate;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function cobertura_build_report(mysqli $mysqli, string $mode, string $selectedDate, ?int $selectedSupervisorId = null, ?int $selectedGroupId = null): string {
    $date = cobertura_parse_date($selectedDate) ?: date('Y-m-d');
    $mode = 'day';
    $startDate = $date;
    $endDate = $date;
    $monthStartDate = date('Y-m-01', strtotime($date));

    $supervisores = cobertura_fetch_supervisores($mysqli);
    $supervisoresById = [];
    foreach ($supervisores as $supervisor) {
        $supervisoresById[(int)$supervisor['id']] = $supervisor;
    }

    $vendors = cobertura_fetch_vendedores($mysqli);
    $vendorsByCode = [];
    foreach ($vendors as $vendor) {
        $vendorsByCode[$vendor['codigo']] = $vendor;
    }

    $groups = cobertura_fetch_active_groups($mysqli, $startDate, $endDate);
    if ($selectedGroupId !== null && $selectedGroupId > 0) {
        $groups = array_values(array_filter($groups, function(array $group) use ($selectedGroupId): bool {
            return (int)($group['id'] ?? 0) === (int)$selectedGroupId;
        }));
    }
    if (!$groups) {
        return '<p>No hay grupos de cobertura activos para el rango seleccionado.</p>';
    }
    if (!cobertura_table_exists($mysqli, 'comprobantes_detallados')) {
        return '<p>La tabla comprobantes_detallados no existe o no está disponible.</p>';
    }
    if (!cobertura_table_exists($mysqli, 'pedidos_x_dia_detallado')) {
        return '<p>La tabla pedidos_x_dia_detallado no existe o no está disponible.</p>';
    }

    $groupIds = [];
    $groupsById = [];
    foreach ($groups as $group) {
        $groupIds[] = (int)$group['id'];
        $groupsById[(int)$group['id']] = $group;
    }

    $metaRows = cobertura_fetch_active_meta_rows($mysqli, $groupIds, $startDate, $endDate);
    $metaByGroupMesaVendor = [];
    foreach ($metaRows as $metaRow) {
        $vendorCodeMeta = cobertura_normalize_vendor_code((string)($metaRow['cod_vendedor'] ?? ''));
        if ($vendorCodeMeta === '') {
            continue;
        }
        $vendorInfoMeta = $vendorsByCode[$vendorCodeMeta] ?? null;
        $vendorSupervisorMeta = $vendorInfoMeta['supervisor_id'] ?? null;
        if ($selectedSupervisorId !== null && $selectedSupervisorId > 0) {
            if ($vendorSupervisorMeta === null || (int)$vendorSupervisorMeta !== (int)$selectedSupervisorId) {
                continue;
            }
        }
        $groupIdMeta = (int)$metaRow['grupo_id'];
        $mesaKey = $vendorSupervisorMeta !== null ? (int)$vendorSupervisorMeta : 0;
        if (!isset($metaByGroupMesaVendor[$groupIdMeta])) {
            $metaByGroupMesaVendor[$groupIdMeta] = [];
        }
        if (!isset($metaByGroupMesaVendor[$groupIdMeta][$mesaKey])) {
            $metaByGroupMesaVendor[$groupIdMeta][$mesaKey] = [];
        }
        if (!isset($metaByGroupMesaVendor[$groupIdMeta][$mesaKey][$vendorCodeMeta])) {
            $metaByGroupMesaVendor[$groupIdMeta][$mesaKey][$vendorCodeMeta] = 0.0;
        }
        $metaByGroupMesaVendor[$groupIdMeta][$mesaKey][$vendorCodeMeta] += (float)($metaRow['meta_unidades'] ?? 0);
    }

    $codeRows = cobertura_fetch_group_codes($mysqli, $groupIds);
    $codes = [];
    $groupCodes = [];
    foreach ($codeRows as $row) {
        $groupId = (int)$row['grupo_id'];
        $code = cobertura_normalize_product_code((string)$row['codigo_producto']);
        if ($code === '') {
            continue;
        }
        $codes[$code] = true;
        if (!isset($groupCodes[$groupId])) {
            $groupCodes[$groupId] = [];
        }
        $groupCodes[$groupId][$code] = [
            'codigo' => $code,
            'nombre' => trim((string)($row['nombre_producto'] ?? '')),
        ];
    }
    if (!$codes) {
        return '<p>Los grupos activos no tienen códigos de producto registrados.</p>';
    }

        $allCodes = array_keys($codes);
        $placeholders = implode(',', array_fill(0, count($allCodes), '?'));
        $monthCutoffDate = $date;
        $monthRows = [];
        if ($monthCutoffDate >= $monthStartDate) {
                $types = 'ss' . str_repeat('s', count($allCodes));
                $sql = "SELECT fecha, codigovendedor, nombrevendedor, codigocliente, numcp, codigoproducto, abreviacion
                                FROM comprobantes_detallados
                                WHERE fecha BETWEEN ? AND ?
                                    AND codigoproducto IN ({$placeholders})
                                ORDER BY fecha ASC, codigovendedor ASC, codigoproducto ASC";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                        return '<p>No se pudo preparar la consulta acumulada de cobertura.</p>';
                }
            $params = array_merge([$monthStartDate, $monthCutoffDate], $allCodes);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $monthRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                $stmt->close();
        }

        $typesDay = 's' . str_repeat('s', count($allCodes));
        $sqlDay = "SELECT fecha, cod_vendedor, nom_vendedor, codigo, cod_producto
                             FROM pedidos_x_dia_detallado
                             WHERE fecha = ?
                                 AND cod_producto IN ({$placeholders})
                             ORDER BY cod_vendedor ASC, cod_producto ASC";
        $stmtDay = $mysqli->prepare($sqlDay);
        if (!$stmtDay) {
                return '<p>No se pudo preparar la consulta diaria de cobertura.</p>';
        }
        $paramsDay = array_merge([$date], $allCodes);
        $stmtDay->bind_param($typesDay, ...$paramsDay);
        $stmtDay->execute();
        $resDay = $stmtDay->get_result();
        $dayRows = $resDay ? $resDay->fetch_all(MYSQLI_ASSOC) : [];
        $stmtDay->close();

        $qtyByGroupMesaVendor = [];
        $monthSeenClientsByGroup = [];

    $groupsByCode = [];
    foreach ($groupCodes as $groupId => $items) {
        foreach ($items as $code => $productRow) {
            if (!isset($groupsByCode[$code])) {
                $groupsByCode[$code] = [];
            }
            $groupsByCode[$code][] = (int)$groupId;
        }
    }

    $monthCandidateByGroup = [];
    foreach ($monthRows as $row) {
        $code = cobertura_normalize_product_code((string)($row['codigoproducto'] ?? ''));
        if ($code === '' || empty($groupsByCode[$code])) {
            continue;
        }
        $rowDate = cobertura_parse_date((string)($row['fecha'] ?? ''));
        if ($rowDate === null) {
            continue;
        }
        $vendorCode = cobertura_normalize_vendor_code((string)($row['codigovendedor'] ?? ''));
        $vendorName = trim((string)($row['nombrevendedor'] ?? ''));
        $clientCode = trim((string)($row['codigocliente'] ?? ''));
        $documentCode = trim((string)($row['numcp'] ?? ''));
        $abreviacion = cobertura_normalize_abreviacion((string)($row['abreviacion'] ?? ''));
        $vendorInfo = $vendorsByCode[$vendorCode] ?? null;
        $vendorSupervisorId = $vendorInfo['supervisor_id'] ?? null;

        if ($selectedSupervisorId !== null && $selectedSupervisorId > 0) {
            if ($vendorSupervisorId === null || (int)$vendorSupervisorId !== (int)$selectedSupervisorId) {
                continue;
            }
        }

        foreach ($groupsByCode[$code] as $groupId) {
            $mesaKey = $vendorSupervisorId !== null ? (int)$vendorSupervisorId : 0;
            if ($vendorCode === '' || $clientCode === '') {
                continue;
            }

            if (!isset($monthCandidateByGroup[$groupId])) {
                $monthCandidateByGroup[$groupId] = [];
            }
            if (!isset($monthCandidateByGroup[$groupId][$rowDate])) {
                $monthCandidateByGroup[$groupId][$rowDate] = [];
            }
            if (!isset($monthCandidateByGroup[$groupId][$rowDate][$mesaKey])) {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey] = [];
            }
            if (!isset($monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode])) {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode] = [];
            }
            if (!isset($monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode])) {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode] = [
                    'vendor_name' => $vendorName !== '' ? $vendorName : (($vendorInfo['nombre'] ?? '') !== '' ? (string)$vendorInfo['nombre'] : $vendorCode),
                    'documents' => [],
                ];
            }

            $documentKey = $documentCode !== '' ? $documentCode : '__NO_NUMCP__';
            if (!isset($monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode]['documents'][$documentKey])) {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode]['documents'][$documentKey] = [
                    'has_sale' => false,
                    'has_credit' => false,
                ];
            }

            if ($abreviacion === 'BV00' || $abreviacion === 'FV00') {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode]['documents'][$documentKey]['has_sale'] = true;
            } elseif ($abreviacion === 'NC04' || $abreviacion === 'NC05') {
                $monthCandidateByGroup[$groupId][$rowDate][$mesaKey][$vendorCode][$clientCode]['documents'][$documentKey]['has_credit'] = true;
            }
        }
    }

    foreach ($monthCandidateByGroup as $groupId => $dateBuckets) {
        ksort($dateBuckets);
        foreach ($dateBuckets as $rowDate => $mesaBuckets) {
            foreach ($mesaBuckets as $mesaKey => $vendorBuckets) {
                foreach ($vendorBuckets as $vendorCode => $clientBuckets) {
                    foreach ($clientBuckets as $clientCode => $candidate) {
                        $documents = is_array($candidate['documents'] ?? null) ? $candidate['documents'] : [];
                        $hasSale = false;
                        $hasCredit = false;
                        foreach ($documents as $documentFlags) {
                            if (!empty($documentFlags['has_sale'])) {
                                $hasSale = true;
                            }
                            if (!empty($documentFlags['has_credit'])) {
                                $hasCredit = true;
                            }
                        }
                        if (!$hasSale || $hasCredit) {
                            continue;
                        }
                        if (!isset($qtyByGroupMesaVendor[$groupId])) {
                            $qtyByGroupMesaVendor[$groupId] = [];
                        }
                        if (!isset($qtyByGroupMesaVendor[$groupId][$mesaKey])) {
                            $qtyByGroupMesaVendor[$groupId][$mesaKey] = [];
                        }
                        if (!isset($qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode])) {
                            $qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode] = [
                                'clients' => [],
                                'month_clients' => [],
                                'vendor_name' => (string)($candidate['vendor_name'] ?? $vendorCode),
                            ];
                        }
                        if (!isset($monthSeenClientsByGroup[$groupId])) {
                            $monthSeenClientsByGroup[$groupId] = [];
                        }
                        if (!isset($monthSeenClientsByGroup[$groupId][$vendorCode])) {
                            $monthSeenClientsByGroup[$groupId][$vendorCode] = [];
                        }
                        if (isset($monthSeenClientsByGroup[$groupId][$vendorCode][$clientCode])) {
                            continue;
                        }
                        $monthSeenClientsByGroup[$groupId][$vendorCode][$clientCode] = [
                            'date' => $rowDate,
                            'mesa' => $mesaKey,
                            'vendor' => $vendorCode,
                        ];
                        $qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode]['month_clients'][$clientCode] = true;
                    }
                }
            }
        }
    }

    foreach ($dayRows as $row) {
        $code = cobertura_normalize_product_code((string)($row['cod_producto'] ?? ''));
        if ($code === '' || empty($groupsByCode[$code])) {
            continue;
        }
        $vendorCode = cobertura_normalize_vendor_code((string)($row['cod_vendedor'] ?? ''));
        if ($vendorCode === '') {
            continue;
        }
        $vendorName = trim((string)($row['nom_vendedor'] ?? ''));
        $clientCode = trim((string)($row['codigo'] ?? ''));
        $vendorInfo = $vendorsByCode[$vendorCode] ?? null;
        $vendorSupervisorId = $vendorInfo['supervisor_id'] ?? null;
        if ($selectedSupervisorId !== null && $selectedSupervisorId > 0) {
            if ($vendorSupervisorId === null || (int)$vendorSupervisorId !== (int)$selectedSupervisorId) {
                continue;
            }
        }
        foreach ($groupsByCode[$code] as $groupId) {
            if ($clientCode === '') {
                continue;
            }
            $mesaKey = $vendorSupervisorId !== null ? (int)$vendorSupervisorId : 0;
            if (!isset($qtyByGroupMesaVendor[$groupId])) {
                $qtyByGroupMesaVendor[$groupId] = [];
            }
            if (!isset($qtyByGroupMesaVendor[$groupId][$mesaKey])) {
                $qtyByGroupMesaVendor[$groupId][$mesaKey] = [];
            }
            if (!isset($qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode])) {
                $qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode] = [
                    'clients' => [],
                    'month_clients' => [],
                    'vendor_name' => $vendorName !== '' ? $vendorName : (($vendorInfo['nombre'] ?? '') !== '' ? (string)$vendorInfo['nombre'] : $vendorCode),
                ];
            }
            $qtyByGroupMesaVendor[$groupId][$mesaKey][$vendorCode]['clients'][$clientCode] = true;
        }
    }

    $title = 'Cobertura del día ' . date('d/m/Y', strtotime($date));
    $supervisorLabel = 'Todas las mesas';
    if ($selectedSupervisorId !== null && $selectedSupervisorId > 0 && isset($supervisoresById[$selectedSupervisorId])) {
        $supervisorLabel = (string)$supervisoresById[$selectedSupervisorId]['label'];
    }
    $groupLabel = 'Todos los grupos';
    if ($selectedGroupId !== null && $selectedGroupId > 0) {
        foreach ($groups as $group) {
            if ((int)$group['id'] === (int)$selectedGroupId) {
                $groupLabel = (string)$group['nombre_grupo'];
                break;
            }
        }
    }

    $coverageLabel = 'Clientes ' . date('d/m', strtotime($date));
    $monthCoverageLabel = 'Cob. acum. mes';
    ob_start();
    echo '<div class="alm-foco-report">';
    echo '<div class="alm-foco-report-head">';
    echo '<div><strong>' . cobertura_h($title) . '</strong><div class="alm-foco-report-sub">Fecha: ' . cobertura_h($date) . ' | Grupo: ' . cobertura_h($groupLabel) . ' | Mesa: ' . cobertura_h($supervisorLabel) . '</div></div>';
    echo '<div class="alm-foco-report-actions"><button type="button" onclick="window.print()">Imprimir</button></div>';
    echo '</div>';

    $vendorSeedByMesa = [];
    foreach ($vendors as $vendor) {
        $mesaKey = isset($vendor['supervisor_id']) && $vendor['supervisor_id'] !== null ? (int)$vendor['supervisor_id'] : 0;
        if ($selectedSupervisorId !== null && $selectedSupervisorId > 0 && $mesaKey !== (int)$selectedSupervisorId) {
            continue;
        }
        if (!isset($vendorSeedByMesa[$mesaKey])) {
            $vendorSeedByMesa[$mesaKey] = [];
        }
        $vendorSeedByMesa[$mesaKey][$vendor['codigo']] = [
            'codigo' => $vendor['codigo'],
            'nombre' => (string)($vendor['nombre'] ?? ''),
        ];
    }

    $hasRows = false;
    foreach ($groups as $group) {
        $groupId = (int)$group['id'];
        $mesaMap = [];
        $mesaKeys = [];
        foreach (array_keys($metaByGroupMesaVendor[$groupId] ?? []) as $mesaKey) {
            $mesaKeys[(int)$mesaKey] = true;
        }
        foreach (array_keys($qtyByGroupMesaVendor[$groupId] ?? []) as $mesaKey) {
            $mesaKeys[(int)$mesaKey] = true;
        }
        if ($selectedSupervisorId !== null && $selectedSupervisorId > 0) {
            $mesaKeys[(int)$selectedSupervisorId] = true;
        } else {
            foreach (array_keys($vendorSeedByMesa) as $mesaKey) {
                $mesaKeys[(int)$mesaKey] = true;
            }
        }
        foreach (array_keys($mesaKeys) as $mesaKey) {
            $mesaLabel = 'Sin mesa';
            $mesaCode = '';
            if ((int)$mesaKey > 0 && isset($supervisoresById[(int)$mesaKey])) {
                $mesaLabel = (string)$supervisoresById[(int)$mesaKey]['label'];
                $mesaCode = (string)($supervisoresById[(int)$mesaKey]['mesa'] ?? '');
            }
            $vendorMap = [];
            foreach (($vendorSeedByMesa[(int)$mesaKey] ?? []) as $vendorCode => $vendorInfo) {
                $vendorMap[$vendorCode] = [
                    'codigo' => $vendorCode,
                    'nombre' => (string)$vendorInfo['nombre'],
                    'meta' => 0.0,
                    'month_coverage' => 0.0,
                    'qty' => 0.0,
                ];
            }
            foreach (($metaByGroupMesaVendor[$groupId][(int)$mesaKey] ?? []) as $vendorCode => $metaValue) {
                if (!isset($vendorMap[$vendorCode])) {
                    $vendorMap[$vendorCode] = [
                        'codigo' => $vendorCode,
                        'nombre' => (string)($vendorsByCode[$vendorCode]['nombre'] ?? ''),
                        'meta' => 0.0,
                        'month_coverage' => 0.0,
                        'qty' => 0.0,
                    ];
                }
                $vendorMap[$vendorCode]['meta'] = (float)$metaValue;
            }
            foreach (($qtyByGroupMesaVendor[$groupId][(int)$mesaKey] ?? []) as $vendorCode => $qtyData) {
                if (!isset($vendorMap[$vendorCode])) {
                    $vendorMap[$vendorCode] = [
                        'codigo' => $vendorCode,
                        'nombre' => (string)($qtyData['vendor_name'] ?? ($vendorsByCode[$vendorCode]['nombre'] ?? '')),
                        'meta' => 0.0,
                        'month_coverage' => 0.0,
                        'qty' => 0.0,
                    ];
                }
                $vendorMap[$vendorCode]['month_coverage'] = (float)count($qtyData['month_clients'] ?? []);
                $vendorMap[$vendorCode]['qty'] = (float)count($qtyData['clients'] ?? []);
            }
            uasort($vendorMap, function(array $a, array $b): int {
                $metaA = (float)($a['meta'] ?? 0);
                $metaB = (float)($b['meta'] ?? 0);
                $qtyA = (float)($a['qty'] ?? 0);
                $qtyB = (float)($b['qty'] ?? 0);
                $scoreA = ($metaA > 0 || $qtyA > 0) ? 1 : 0;
                $scoreB = ($metaB > 0 || $qtyB > 0) ? 1 : 0;
                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }
                if ($metaA !== $metaB) {
                    return $metaB <=> $metaA;
                }
                return strnatcmp((string)($a['codigo'] ?? ''), (string)($b['codigo'] ?? ''));
            });
            $mesaMap[(int)$mesaKey] = [
                'supervisor_id' => (int)$mesaKey,
                'label' => $mesaLabel,
                'mesa' => $mesaCode,
                'vendors' => $vendorMap,
            ];
        }

        if (!$mesaMap) {
            continue;
        }
        uasort($mesaMap, function(array $a, array $b): int {
            $mesaA = trim((string)($a['mesa'] ?? ''));
            $mesaB = trim((string)($b['mesa'] ?? ''));
            if ($mesaA !== '' || $mesaB !== '') {
                return strnatcmp($mesaA, $mesaB);
            }
            return strnatcmp((string)$a['label'], (string)$b['label']);
        });

        $hasRows = true;
        $detailCodes = [];
        foreach (($groupCodes[$groupId] ?? []) as $product) {
            $label = $product['codigo'];
            if ($product['nombre'] !== '') {
                $label .= ' - ' . $product['nombre'];
            }
            $detailCodes[] = $label;
        }
        echo '<div class="alm-cobertura-day-card">';
        echo '<div class="alm-cobertura-day-card-head">';
        echo '<div class="alm-cobertura-day-title">' . cobertura_h($group['nombre_grupo']) . '</div>';
        echo '<div class="alm-foco-report-sub">Códigos: ' . cobertura_h(implode(', ', $detailCodes)) . '</div>';
        if (!empty($group['observacion'])) {
            echo '<div class="alm-foco-report-sub">' . cobertura_h($group['observacion']) . '</div>';
        }
        echo '</div>';
        echo '<table class="alm-foco-table alm-cobertura-day-table">';
        echo '<thead><tr><th>Vendedor</th><th>Cuota</th><th>' . cobertura_h($monthCoverageLabel) . '</th><th>' . cobertura_h($coverageLabel) . '</th><th>Total Acum+Dia</th><th>%</th><th>Por Coberturar</th></tr></thead><tbody>';
        $groupTotalMeta = 0.0;
        $groupTotalMonthCoverage = 0.0;
        $groupTotalQty = 0.0;
        foreach ($mesaMap as $mesaBucket) {
            $mesaVendors = $mesaBucket['vendors'] ?? [];
            $mesaTotalMeta = 0.0;
            $mesaTotalMonthCoverage = 0.0;
            $mesaTotalQty = 0.0;
            $hasMesaRows = false;
            echo '<tr class="alm-cobertura-day-mesa-row"><th colspan="7">' . cobertura_h($mesaBucket['label']) . '</th></tr>';
            foreach ($mesaVendors as $vendorBucket) {
                $meta = (float)($vendorBucket['meta'] ?? 0);
                $monthCoverage = (float)($vendorBucket['month_coverage'] ?? 0);
                $qty = (float)($vendorBucket['qty'] ?? 0);
                $advanceCoverage = $monthCoverage + $qty;
                $hasMesaRows = true;
                $pct = $meta > 0 ? ($advanceCoverage / $meta) * 100 : 0.0;
                $remaining = max($meta - $advanceCoverage, 0.0);
                $mesaTotalMeta += $meta;
                $mesaTotalMonthCoverage += $monthCoverage;
                $mesaTotalQty += $qty;
                echo '<tr>';
                echo '<td>' . cobertura_h($vendorBucket['codigo'] . ' - ' . $vendorBucket['nombre']) . '</td>';
                echo '<td>' . number_format($meta, 2, '.', ',') . '</td>';
                echo '<td>' . number_format($monthCoverage, 2, '.', ',') . '</td>';
                echo '<td>' . number_format($qty, 2, '.', ',') . '</td>';
                echo '<td>' . number_format($advanceCoverage, 2, '.', ',') . '</td>';
                echo '<td class="alm-cobertura-day-pct">' . number_format($pct, 1, '.', ',') . '%</td>';
                echo '<td>' . number_format($remaining, 2, '.', ',') . '</td>';
                echo '</tr>';
            }
            if (!$hasMesaRows) {
                echo '<tr><td colspan="7">No hay vendedores con meta o cobertura para esta mesa.</td></tr>';
            }
            $groupTotalMeta += $mesaTotalMeta;
            $groupTotalMonthCoverage += $mesaTotalMonthCoverage;
            $groupTotalQty += $mesaTotalQty;
            $mesaAdvanceCoverage = $mesaTotalMonthCoverage + $mesaTotalQty;
            $mesaPct = $mesaTotalMeta > 0 ? ($mesaAdvanceCoverage / $mesaTotalMeta) * 100 : 0.0;
            echo '<tr class="alm-cobertura-day-subtotal-row">';
            echo '<th>Subtotal ' . cobertura_h($mesaBucket['label']) . '</th>';
            echo '<th>' . number_format($mesaTotalMeta, 2, '.', ',') . '</th>';
            echo '<th>' . number_format($mesaTotalMonthCoverage, 2, '.', ',') . '</th>';
            echo '<th>' . number_format($mesaTotalQty, 2, '.', ',') . '</th>';
            echo '<th>' . number_format($mesaAdvanceCoverage, 2, '.', ',') . '</th>';
            echo '<th class="alm-cobertura-day-pct">' . number_format($mesaPct, 1, '.', ',') . '%</th>';
            echo '<th>' . number_format(max($mesaTotalMeta - $mesaAdvanceCoverage, 0.0), 2, '.', ',') . '</th>';
            echo '</tr>';
        }
        $groupAdvanceCoverage = $groupTotalMonthCoverage + $groupTotalQty;
        $totalPct = $groupTotalMeta > 0 ? ($groupAdvanceCoverage / $groupTotalMeta) * 100 : 0.0;
        echo '</tbody><tfoot><tr>';
        echo '<th>Total</th>';
        echo '<th>' . number_format($groupTotalMeta, 2, '.', ',') . '</th>';
        echo '<th>' . number_format($groupTotalMonthCoverage, 2, '.', ',') . '</th>';
        echo '<th>' . number_format($groupTotalQty, 2, '.', ',') . '</th>';
        echo '<th>' . number_format($groupAdvanceCoverage, 2, '.', ',') . '</th>';
        echo '<th class="alm-cobertura-day-pct">' . number_format($totalPct, 1, '.', ',') . '%</th>';
        echo '<th>' . number_format(max($groupTotalMeta - $groupAdvanceCoverage, 0.0), 2, '.', ',') . '</th>';
        echo '</tr></tfoot></table>';
        echo '</div>';
    }
    if (!$hasRows) {
        echo '<p>No hay datos de cobertura para la fecha seleccionada.</p>';
    }
    echo '</div>';
    return (string)ob_get_clean();
}

cobertura_ensure_tables($mysqli);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'groups_list');

if ($action === 'options') {
    cobertura_json([
        'ok' => true,
        'grupos' => array_map(function(array $row): array {
            return [
                'id' => (int)$row['id'],
                'nombre' => (string)$row['nombre_grupo'],
                'activo' => (int)$row['activo'] === 1,
            ];
        }, cobertura_fetch_groups($mysqli)),
        'vendedores' => cobertura_fetch_vendedores($mysqli),
        'supervisores' => cobertura_fetch_supervisores($mysqli),
    ]);
}

if ($action === 'groups_list') {
    header('Content-Type: text/html; charset=utf-8');
    echo cobertura_render_groups_table(cobertura_fetch_groups($mysqli));
    exit;
}

if ($action === 'group_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nombre = trim((string)($_POST['nombre_grupo'] ?? ''));
    $meta = isset($_POST['meta_unidades']) ? (float)$_POST['meta_unidades'] : 0.0;
    $desde = cobertura_parse_date($_POST['fecha_inicio'] ?? '');
    $hasta = cobertura_parse_date($_POST['fecha_fin'] ?? '');
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : (isset($_POST['activo']) && (string)$_POST['activo'] === 'on' ? 1 : 0);
    $codes = cobertura_parse_codes($_POST['codigos'] ?? '');

    if ($nombre === '' || !$desde || !$hasta || !$codes) {
        cobertura_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    if ($desde > $hasta) {
        cobertura_json(['ok' => false, 'error' => 'INVALID_RANGE'], 400);
    }

    $names = cobertura_lookup_product_names($mysqli, $codes);
    $mysqli->begin_transaction();
    try {
        if ($id > 0) {
            $stmt = $mysqli->prepare('UPDATE cobertura_grupos SET nombre_grupo = ?, meta_unidades = ?, fecha_inicio = ?, fecha_fin = ?, activo = ?, observacion = ? WHERE id = ?');
            $stmt->bind_param('sdssisi', $nombre, $meta, $desde, $hasta, $activo, $observacion, $id);
            $stmt->execute();
            $stmt->close();

            $stmtDelete = $mysqli->prepare('DELETE FROM cobertura_grupo_codigos WHERE grupo_id = ?');
            $stmtDelete->bind_param('i', $id);
            $stmtDelete->execute();
            $stmtDelete->close();
        } else {
            $stmt = $mysqli->prepare('INSERT INTO cobertura_grupos (nombre_grupo, meta_unidades, fecha_inicio, fecha_fin, activo, observacion) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('sdssis', $nombre, $meta, $desde, $hasta, $activo, $observacion);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        $stmtCode = $mysqli->prepare('INSERT INTO cobertura_grupo_codigos (grupo_id, codigo_producto, nombre_producto) VALUES (?,?,?)');
        foreach ($codes as $code) {
            $name = $names[$code] ?? null;
            $stmtCode->bind_param('iss', $id, $code, $name);
            $stmtCode->execute();
        }
        $stmtCode->close();
        $mysqli->commit();
        cobertura_json(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        cobertura_json(['ok' => false, 'error' => 'DB_ERROR', 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'metas_list') {
    header('Content-Type: text/html; charset=utf-8');
    echo cobertura_render_meta_table(cobertura_fetch_meta_rows($mysqli));
    exit;
}

if ($action === 'meta_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    $codVendedor = cobertura_normalize_vendor_code($_POST['cod_vendedor'] ?? '');
    $metaUnidades = (float)($_POST['meta_unidades'] ?? 0);
    $fechaInicio = cobertura_parse_date($_POST['fecha_inicio'] ?? '');
    $fechaFin = cobertura_parse_date($_POST['fecha_fin'] ?? '');
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    if ($grupoId <= 0 || $codVendedor === '' || $metaUnidades <= 0) {
        cobertura_json(['ok' => false, 'error' => 'REQUIRED'], 400);
    }
    if ($fechaInicio !== null && $fechaFin !== null && $fechaFin < $fechaInicio) {
        cobertura_json(['ok' => false, 'error' => 'INVALID_RANGE'], 400);
    }
    if ($id > 0) {
        $stmt = $mysqli->prepare('UPDATE cobertura_grupo_metas SET grupo_id=?, cod_vendedor=?, meta_unidades=?, fecha_inicio=?, fecha_fin=?, activo=?, observacion=? WHERE id=?');
        if (!$stmt) {
            cobertura_json(['ok' => false, 'error' => 'DB'], 500);
        }
        $stmt->bind_param('isdssisi', $grupoId, $codVendedor, $metaUnidades, $fechaInicio, $fechaFin, $activo, $observacion, $id);
        $ok = $stmt->execute();
        $stmt->close();
        cobertura_json(['ok' => $ok ? true : false]);
    }
    $stmt = $mysqli->prepare('INSERT INTO cobertura_grupo_metas (grupo_id, cod_vendedor, meta_unidades, fecha_inicio, fecha_fin, activo, observacion) VALUES (?,?,?,?,?,?,?)');
    if (!$stmt) {
        cobertura_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('isdssis', $grupoId, $codVendedor, $metaUnidades, $fechaInicio, $fechaFin, $activo, $observacion);
    $ok = $stmt->execute();
    $stmt->close();
    cobertura_json(['ok' => $ok ? true : false]);
}

if ($action === 'meta_bulk_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    $supervisorId = (int)($_POST['supervisor_id'] ?? 0);
    $fechaInicio = cobertura_parse_date($_POST['fecha_inicio'] ?? '');
    $fechaFin = cobertura_parse_date($_POST['fecha_fin'] ?? '');
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if ($grupoId <= 0 || $supervisorId <= 0 || !is_array($items)) {
        cobertura_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    if ($fechaInicio !== null && $fechaFin !== null && $fechaFin < $fechaInicio) {
        cobertura_json(['ok' => false, 'error' => 'INVALID_RANGE'], 400);
    }

    $validCodes = [];
    foreach (cobertura_fetch_vendedores($mysqli) as $vendor) {
        if ((int)($vendor['supervisor_id'] ?? 0) === $supervisorId) {
            $validCodes[(string)$vendor['codigo']] = true;
        }
    }
    if (!$validCodes) {
        cobertura_json(['ok' => false, 'error' => 'NO_VENDORS'], 400);
    }

    $saved = 0;
    $mysqli->begin_transaction();
    try {
        $stmtUpdate = $mysqli->prepare('UPDATE cobertura_grupo_metas SET grupo_id=?, cod_vendedor=?, meta_unidades=?, fecha_inicio=?, fecha_fin=?, activo=?, observacion=? WHERE id=?');
        $stmtFind = $mysqli->prepare('SELECT id FROM cobertura_grupo_metas WHERE grupo_id=? AND cod_vendedor=? AND fecha_inicio <=> ? AND fecha_fin <=> ? LIMIT 1');
        $stmtInsert = $mysqli->prepare('INSERT INTO cobertura_grupo_metas (grupo_id, cod_vendedor, meta_unidades, fecha_inicio, fecha_fin, activo, observacion) VALUES (?,?,?,?,?,?,?)');
        if (!$stmtUpdate || !$stmtFind || !$stmtInsert) {
            throw new Exception('DB_PREPARE');
        }

        foreach ($items as $item) {
            $codVendedor = cobertura_normalize_vendor_code($item['cod'] ?? '');
            $metaUnidades = isset($item['meta']) ? (float)$item['meta'] : 0.0;
            $id = isset($item['id']) && $item['id'] !== '' ? (int)$item['id'] : 0;
            if ($codVendedor === '' || $metaUnidades <= 0 || !isset($validCodes[$codVendedor])) {
                continue;
            }
            if ($id > 0) {
                $stmtUpdate->bind_param('isdssisi', $grupoId, $codVendedor, $metaUnidades, $fechaInicio, $fechaFin, $activo, $observacion, $id);
                $stmtUpdate->execute();
                $saved++;
                continue;
            }

            $stmtFind->bind_param('isss', $grupoId, $codVendedor, $fechaInicio, $fechaFin);
            $stmtFind->execute();
            $res = $stmtFind->get_result();
            $existing = $res ? $res->fetch_assoc() : null;
            if ($res) {
                $res->close();
            }

            if ($existing && !empty($existing['id'])) {
                $existingId = (int)$existing['id'];
                $stmtUpdate->bind_param('isdssisi', $grupoId, $codVendedor, $metaUnidades, $fechaInicio, $fechaFin, $activo, $observacion, $existingId);
                $stmtUpdate->execute();
            } else {
                $stmtInsert->bind_param('isdssis', $grupoId, $codVendedor, $metaUnidades, $fechaInicio, $fechaFin, $activo, $observacion);
                $stmtInsert->execute();
            }
            $saved++;
        }

        $stmtUpdate->close();
        $stmtFind->close();
        $stmtInsert->close();
        $mysqli->commit();
        cobertura_json(['ok' => true, 'saved' => $saved]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        cobertura_json(['ok' => false, 'error' => 'DB_ERROR', 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'meta_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        cobertura_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    $stmt = $mysqli->prepare('DELETE FROM cobertura_grupo_metas WHERE id=?');
    if (!$stmt) {
        cobertura_json(['ok' => false, 'error' => 'DB'], 500);
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    cobertura_json(['ok' => $ok ? true : false]);
}

if ($action === 'group_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        cobertura_json(['ok' => false, 'error' => 'PARAMS'], 400);
    }
    $mysqli->begin_transaction();
    try {
        $stmtCodes = $mysqli->prepare('DELETE FROM cobertura_grupo_codigos WHERE grupo_id = ?');
        $stmtCodes->bind_param('i', $id);
        $stmtCodes->execute();
        $stmtCodes->close();

        $stmtGroup = $mysqli->prepare('DELETE FROM cobertura_grupos WHERE id = ?');
        $stmtGroup->bind_param('i', $id);
        $stmtGroup->execute();
        $stmtGroup->close();
        $mysqli->commit();
        cobertura_json(['ok' => true]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        cobertura_json(['ok' => false, 'error' => 'DB_ERROR', 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'avance') {
    header('Content-Type: text/html; charset=utf-8');
    $supervisorId = isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' ? (int)$_GET['supervisor_id'] : null;
    $groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;
    echo cobertura_build_report($mysqli, (string)($_GET['mode'] ?? 'day'), (string)($_GET['date'] ?? date('Y-m-d')), $supervisorId, $groupId);
    exit;
}

cobertura_json(['ok' => false, 'error' => 'ACTION'], 400);