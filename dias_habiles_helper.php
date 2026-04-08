<?php

function ensureDiasHabilesTable(mysqli $mysqli): void {
    $mysqli->query("CREATE TABLE IF NOT EXISTS dias_habiles_mes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anio SMALLINT NOT NULL,
        mes TINYINT NOT NULL,
        fecha DATE NOT NULL,
        habil TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dias_habiles_fecha (fecha),
        KEY idx_dias_habiles_mes (anio, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function seedDiasHabilesMonth(mysqli $mysqli, int $anio, int $mes): void {
    ensureDiasHabilesTable($mysqli);
    $start = DateTimeImmutable::createFromFormat('Y-n-j', $anio . '-' . $mes . '-1');
    if (!$start) {
        return;
    }
    $cursor = $start;
    $month = (int)$start->format('n');
    $stmt = $mysqli->prepare('INSERT IGNORE INTO dias_habiles_mes (anio, mes, fecha, habil) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }
    while ((int)$cursor->format('n') === $month) {
        $fecha = $cursor->format('Y-m-d');
        $dow = (int)$cursor->format('N');
        $habil = ($dow === 7) ? 0 : 1;
        $stmt->bind_param('iisi', $anio, $mes, $fecha, $habil);
        $stmt->execute();
        $cursor = $cursor->modify('+1 day');
    }
    $stmt->close();
}

function getDiasHabilesMonth(mysqli $mysqli, int $anio, int $mes): array {
    seedDiasHabilesMonth($mysqli, $anio, $mes);
    $items = [];
    $stmt = $mysqli->prepare('SELECT fecha, habil FROM dias_habiles_mes WHERE anio = ? AND mes = ? ORDER BY fecha ASC');
    if (!$stmt) {
        return $items;
    }
    $stmt->bind_param('ii', $anio, $mes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[(string)$row['fecha']] = (int)$row['habil'] === 1;
    }
    $stmt->close();
    return $items;
}

function isConfiguredBusinessDay(mysqli $mysqli, string $fecha): bool {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$dt) {
        return false;
    }
    $map = getDiasHabilesMonth($mysqli, (int)$dt->format('Y'), (int)$dt->format('n'));
    return isset($map[$fecha]) ? $map[$fecha] : false;
}

function countConfiguredBusinessDays(mysqli $mysqli, string $startDate, string $endDate): int {
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end || $end < $start) {
        return 0;
    }
    $count = 0;
    $cursor = $start;
    $cache = [];
    while ($cursor <= $end) {
        $anio = (int)$cursor->format('Y');
        $mes = (int)$cursor->format('n');
        $cacheKey = $anio . '-' . $mes;
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = getDiasHabilesMonth($mysqli, $anio, $mes);
        }
        $fecha = $cursor->format('Y-m-d');
        if (!empty($cache[$cacheKey][$fecha])) {
            $count++;
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $count;
}
