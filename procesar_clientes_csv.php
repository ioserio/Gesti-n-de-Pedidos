<?php
require_once __DIR__ . '/require_login.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_clientes'])) {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '256M');
    $archivoTmp = $_FILES['archivo_clientes']['tmp_name'];
    $archivoSize = $_FILES['archivo_clientes']['size'] ?? 0;
    if (!is_uploaded_file($archivoTmp)) {
        http_response_code(400);
        echo 'No se recibió el archivo correctamente.';
        exit;
    }

    // Conexión
    require_once __DIR__ . '/conexion.php';

    // Opcional: vaciar tabla
    $doTruncate = !empty($_POST['truncate']);
    if ($doTruncate) {
        $mysqli->query('TRUNCATE TABLE clientes');
    }

    // Leer CSV en streaming
    $fh = fopen($archivoTmp, 'r');
    if (!$fh) {
        http_response_code(400);
        echo 'No se pudo abrir el CSV.';
        exit;
    }

    // Detectar separador: coma por defecto
    $delimiter = ',';
    // Leer cabeceras
    $headers = fgetcsv($fh, 0, $delimiter);
    if ($headers === false || empty($headers)) {
        fclose($fh);
        http_response_code(400);
        echo 'El CSV no tiene cabeceras en la primera fila.';
        exit;
    }
    // Normalizar cabeceras
    $headers = array_map(function($h){ return is_string($h) ? trim($h) : $h; }, $headers);

    // Columnas esperadas en la tabla `clientes`
    $cols = [
        'Codigo','Nombre','TipoDocIdentidad','DocIdentidad','Activo','Direccion',
        'CodigoZonaVenta','DescripcionZonaVenta','LineaCredito','CodigoZonaReparto',
        'DescripcionZonaReparto','CategoriaCliente','TipoCliente','Distrito','PKID',
        'IDCategoriaCliente','IDZonaVenta','CCC','RUC','TamanoNegocio','MixProductos',
        'MaquinaExhibidora','CortadorEmbutidos','Visicooler','CajaRegistradora','TelefonoPublico'
    ];

    // Mapeo cabeceras -> índice
    $map = [];
    foreach ($cols as $c) {
        $idx = array_search($c, $headers, true);
        $map[$c] = ($idx !== false) ? $idx : null;
    }

    // Preparar sentencias
    $insertSql = 'INSERT INTO clientes (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
    $insertStmt = $mysqli->prepare($insertSql);
    if (!$insertStmt) {
        die('Error preparando INSERT: ' . $mysqli->error);
    }
    $selByRuc = $mysqli->prepare('SELECT COUNT(*) FROM clientes WHERE RUC = ?');
    $selByDoc = $mysqli->prepare('SELECT COUNT(*) FROM clientes WHERE DocIdentidad = ?');
    $selByCod = $mysqli->prepare('SELECT COUNT(*) FROM clientes WHERE Codigo = ?');
    $updateTpl = function(string $key) use ($cols) {
        $setParts = [];
        foreach ($cols as $c) { if ($c === $key) continue; $setParts[] = "$c = ?"; }
        return 'UPDATE clientes SET ' . implode(',', $setParts) . ' WHERE ' . $key . ' = ?';
    };
    $updateByRuc = $mysqli->prepare($updateTpl('RUC'));
    $updateByDoc = $mysqli->prepare($updateTpl('DocIdentidad'));
    $updateByCod = $mysqli->prepare($updateTpl('Codigo'));
    if (!$selByRuc || !$selByDoc || !$selByCod || !$updateByRuc || !$updateByDoc || !$updateByCod) {
        die('Error preparando sentencias: ' . $mysqli->error);
    }

    $insertados = 0; $actualizados = 0;

    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if (!$row || count($row) === 0) { continue; }
        $vals = [];
        foreach ($cols as $c) {
            $v = ($map[$c] !== null && isset($row[$map[$c]])) ? $row[$map[$c]] : null;
            if (is_string($v)) $v = trim($v);
            if (in_array($c, ['Codigo','CodigoZonaVenta','CodigoZonaReparto','PKID','IDCategoriaCliente','IDZonaVenta'], true)) {
                $v = ($v === '' || $v === null) ? null : (int)preg_replace('/[^0-9\-]/', '', (string)$v);
            } elseif ($c === 'LineaCredito') {
                $v = ($v === '' || $v === null) ? null : (float)str_replace([','], [''], (string)$v);
            } else {
                $v = ($v === '' ? null : $v);
            }
            $vals[] = $v;
        }
        $valIdx = array_flip($cols);
        $ruc = $vals[$valIdx['RUC']] ?? null;
        $doc = $vals[$valIdx['DocIdentidad']] ?? null;
        $cod = $vals[$valIdx['Codigo']] ?? null;

        $mode = 'NONE'; $exists = 0;
        if (!empty($ruc)) { $selByRuc->bind_param('s', $ruc); $selByRuc->execute(); $selByRuc->bind_result($exists); $selByRuc->fetch(); $selByRuc->reset(); $mode = 'RUC'; }
        elseif (!empty($doc)) { $selByDoc->bind_param('s', $doc); $selByDoc->execute(); $selByDoc->bind_result($exists); $selByDoc->fetch(); $selByDoc->reset(); $mode = 'DOC'; }
        elseif (!empty($cod)) { $selByCod->bind_param('i', $cod); $selByCod->execute(); $selByCod->bind_result($exists); $selByCod->fetch(); $selByCod->reset(); $mode = 'COD'; }

        if ($exists > 0 && $mode !== 'NONE') {
            $updVals = [];
            foreach ($cols as $c) { if ($c === 'RUC' && $mode==='RUC') continue; if ($c==='DocIdentidad' && $mode==='DOC') continue; if ($c==='Codigo' && $mode==='COD') continue; $updVals[] = $vals[$valIdx[$c]]; }
            $updVals[] = ($mode==='RUC'?$ruc:($mode==='DOC'?$doc:$cod));
            $types = '';
            foreach ($cols as $c) {
                if ($c === 'RUC' && $mode==='RUC') continue;
                if ($c === 'DocIdentidad' && $mode==='DOC') continue;
                if ($c === 'Codigo' && $mode==='COD') continue;
                $types .= (in_array($c, ['Codigo','CodigoZonaVenta','CodigoZonaReparto','PKID','IDCategoriaCliente','IDZonaVenta'], true) ? 'i' : ($c==='LineaCredito' ? 'd' : 's'));
            }
            $types .= ($mode==='COD' ? 'i' : 's');
            if ($mode==='RUC') { $updateByRuc->bind_param($types, ...$updVals); $updateByRuc->execute(); }
            elseif ($mode==='DOC') { $updateByDoc->bind_param($types, ...$updVals); $updateByDoc->execute(); }
            else { $updateByCod->bind_param($types, ...$updVals); $updateByCod->execute(); }
            $actualizados++;
        } else {
            $types = '';
            foreach ($cols as $c) { $types .= (in_array($c, ['Codigo','CodigoZonaVenta','CodigoZonaReparto','PKID','IDCategoriaCliente','IDZonaVenta'], true) ? 'i' : ($c==='LineaCredito' ? 'd' : 's')); }
            $insertStmt->bind_param($types, ...$vals);
            $insertStmt->execute();
            $insertados++;
        }
    }

    fclose($fh);
    $insertStmt->close(); $selByRuc->close(); $selByDoc->close(); $selByCod->close(); $updateByRuc->close(); $updateByDoc->close(); $updateByCod->close(); $mysqli->close();

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importación de Clientes (CSV)</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
    echo '<h2>Importación de cartera de clientes (CSV)</h2>';
    echo '<p><b>Insertados:</b> ' . (int)$insertados . ' &nbsp; <b>Actualizados:</b> ' . (int)$actualizados . '</p>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

header('Location: index.php');
exit;
