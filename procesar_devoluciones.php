<?php
// procesar_devoluciones.php
// Sube el Excel DEVOLUCIONES POR CLIENTE.xlsx y carga datos en la tabla devoluciones_por_cliente

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

function norm_header($s) {
    $s = trim((string)$s);
    $map = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'];
    $s = strtr($s, $map);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $s));
    return $s;
}

function parse_cantidad($v) {
    // Manejo robusto de separadores decimales: detecta si el valor ya es numérico,
    // o decide el separador decimal por el último símbolo entre ',' y '.'.
    if ($v === null) return 0.0;
    // Si viene ya como número desde PhpSpreadsheet
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $str = trim((string)$v);
    if ($str === '') return 0.0;
    // Eliminar espacios (incl. nbsp)
    $str = str_replace(["\u{00A0}", "\xC2\xA0", ' '], '', $str);
    // Si ahora es numérico directo, devolver
    if (is_numeric($str)) return (float)$str;

    $hasComma = strpos($str, ',') !== false;
    $hasDot   = strpos($str, '.') !== false;

    if ($hasComma && $hasDot) {
        // Elegir como decimal el último separador que aparece en la cadena
        $lastComma = strrpos($str, ',');
        $lastDot   = strrpos($str, '.');
        if ($lastComma > $lastDot) {
            // Coma es decimal -> quitar puntos (miles) y cambiar coma por punto
            $clean = str_replace('.', '', $str);
            $clean = str_replace(',', '.', $clean);
        } else {
            // Punto es decimal -> quitar comas (miles)
            $clean = str_replace(',', '', $str);
            // Dejar punto tal cual
        }
    } elseif ($hasComma && !$hasDot) {
        // Solo coma: usarla como decimal
        $clean = str_replace(',', '.', $str);
    } else {
        // Solo punto o ningún separador: ya está bien
        $clean = $str;
    }
    if (!is_numeric($clean)) return 0.0;
    return (float)$clean;
}

function normalizar_fecha($input) {
    $input = trim((string)$input);
    if ($input === '') return null;
    // Aceptar YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m)) {
        return $input;
    }
    // Aceptar DD/MM/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $input, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $y = $m[3];
        return "$y-$mo-$d";
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_devoluciones'])) {
    $tmp = $_FILES['archivo_devoluciones']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        die('No se recibió un archivo válido.');
    }

    try {
        // Fecha seleccionada por el usuario
        $fechaSel = isset($_POST['fecha_devoluciones']) ? normalizar_fecha($_POST['fecha_devoluciones']) : null;
        if (!$fechaSel) {
            throw new Exception('Debe indicar una fecha válida (YYYY-MM-DD o DD/MM/YYYY).');
        }

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();
        if (!$data || count($data) === 0) {
            throw new Exception('El archivo está vacío o no se pudo leer.');
        }

        // Encabezados esperados
        $expected = [
            'codigovendedor', 'nombrevendedor', 'codigocliente', 'nombrecliente',
            'direccioncliente', 'codigoproducto', 'nombreproducto', 'cantidad', 'vehiculo'
        ];

        $headers = $data[0];
        $mapIdx = [];
        foreach ($headers as $i => $h) {
            $key = norm_header($h);
            // Normalizamos contra claves conocidas (sin espacios/acentos)
            $mapIdx[$key] = $i;
        }
        // Validar que existan todos
        $missing = [];
        foreach ($expected as $k) {
            if (!isset($mapIdx[$k])) $missing[] = $k;
        }
        if (!empty($missing)) {
            throw new Exception('Faltan columnas en el Excel: ' . implode(', ', $missing));
        }

        // Conexión BD
        require_once __DIR__ . '/conexion.php';

        // Asegurar tabla (por si no existe)
        $create = "CREATE TABLE IF NOT EXISTS `devoluciones_por_cliente` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `fecha` DATE NOT NULL,
          `codigovendedor`   VARCHAR(10)  NOT NULL,
          `nombrevendedor`   VARCHAR(100) NULL,
          `codigocliente`    VARCHAR(20)  NOT NULL,
          `nombrecliente`    VARCHAR(150) NULL,
          `direccioncliente` VARCHAR(200) NULL,
          `codigoproducto`   VARCHAR(30)  NOT NULL,
          `nombreproducto`   VARCHAR(150) NULL,
          `cantidad`         DECIMAL(12,2) NOT NULL DEFAULT 0,
          `vehiculo`         VARCHAR(50)  NULL,
          PRIMARY KEY (`id`),
          KEY `idx_fecha` (`fecha`),
          KEY `idx_vendedor` (`codigovendedor`),
          KEY `idx_cliente`  (`codigocliente`),
          KEY `idx_producto` (`codigoproducto`),
          KEY `idx_vehiculo` (`vehiculo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
        if (!$mysqli->query($create)) {
            throw new Exception('Error creando tabla: ' . $mysqli->error);
        }

        // Reemplazar registros de la fecha seleccionada si se marca
        $replaceAll = isset($_POST['replace_all']) && $_POST['replace_all'] == '1';
        if ($replaceAll) {
            $fechaEsc = $mysqli->real_escape_string($fechaSel);
            if (!$mysqli->query("DELETE FROM devoluciones_por_cliente WHERE fecha = '$fechaEsc'")) {
                throw new Exception('Error al limpiar registros de la fecha: ' . $mysqli->error);
            }
        }

        $insert = $mysqli->prepare('INSERT INTO devoluciones_por_cliente (
            fecha, codigovendedor, nombrevendedor, codigocliente, nombrecliente, direccioncliente, codigoproducto, nombreproducto, cantidad, vehiculo
        ) VALUES (?,?,?,?,?,?,?,?,?,?)');
        if (!$insert) {
            throw new Exception('Error preparando INSERT: ' . $mysqli->error);
        }

        $mysqli->begin_transaction();
        for ($r = 1; $r < count($data); $r++) {
            $row = $data[$r];
            // Saltar filas completamente vacías
            $allEmpty = true;
            foreach ($expected as $k) {
                $idx = $mapIdx[$k];
                if (isset($row[$idx]) && trim((string)$row[$idx]) !== '') { $allEmpty = false; break; }
            }
            if ($allEmpty) continue;

            $codVend = trim((string)($row[$mapIdx['codigovendedor']] ?? ''));
            $nomVend = trim((string)($row[$mapIdx['nombrevendedor']] ?? ''));
            $codCli  = trim((string)($row[$mapIdx['codigocliente']] ?? ''));
            $nomCli  = trim((string)($row[$mapIdx['nombrecliente']] ?? ''));
            $dirCli  = trim((string)($row[$mapIdx['direccioncliente']] ?? ''));
            $codProd = trim((string)($row[$mapIdx['codigoproducto']] ?? ''));
            $nomProd = trim((string)($row[$mapIdx['nombreproducto']] ?? ''));
            $cant    = parse_cantidad($row[$mapIdx['cantidad']] ?? null);
            $veh     = trim((string)($row[$mapIdx['vehiculo']] ?? ''));

            $insert->bind_param('ssssssssds', $fechaSel, $codVend, $nomVend, $codCli, $nomCli, $dirCli, $codProd, $nomProd, $cant, $veh);
            if (!$insert->execute()) {
                $mysqli->rollback();
                throw new Exception('Error insertando fila ' . $r . ': ' . $insert->error);
            }
        }
        $mysqli->commit();
        $insert->close();
        $mysqli->close();

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=1280"><title>Devoluciones importadas</title><link rel="stylesheet" href="estilos.css"></head><body>';
        echo '<div class="container">';
        echo '<h2>¡Devoluciones importadas correctamente!</h2>';
        echo '<p>Tabla: <code>devoluciones_por_cliente</code></p>';
        echo '<a href="index.html">Volver</a>';
        echo '</div></body></html>';

    } catch (Throwable $e) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=1280"><title>Error</title><link rel="stylesheet" href="estilos.css"></head><body>';
        echo '<div class="container">';
        echo '<h2>Error al procesar devoluciones</h2>';
        echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<p><a href="index.html">Volver</a></p>';
        echo '</div></body></html>';
    }
} else {
    header('Location: index.html');
    exit();
}
