#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Lee la primera fila (encabezados) del Excel DEVOLUCIONES POR CLIENTE.xlsx
y muestra los nombres de los campos, además de una versión normalizada para SQL.
Uso:
    python leer_campos_excel.py              # Busca "DEVOLUCIONES POR CLIENTE.xlsx" en el mismo folder
    python leer_campos_excel.py -f ruta.xlsx # Indicar ruta específica
Salida:
    - Lista de encabezados (originales)
    - Lista de nombres normalizados (snake_case, sin acentos)
    - Sugerencia de CREATE TABLE con esos campos
"""
from __future__ import annotations
import argparse
import json
import os
import re
import sys
from typing import List, Tuple

try:
    import pandas as pd  # type: ignore
except Exception as e:
    print("[ERROR] Falta la librería 'pandas'. Instala con: pip install pandas openpyxl", file=sys.stderr)
    raise


def normalizar_col(nombre: str) -> str:
    nombre = nombre.strip()
    mapa = {
        'Á':'A','É':'E','Í':'I','Ó':'O','Ú':'U','Ü':'U','Ñ':'N',
        'á':'a','é':'e','í':'i','ó':'o','ú':'u','ü':'u','ñ':'n'
    }
    for k, v in mapa.items():
        nombre = nombre.replace(k, v)
    # Reemplazar cualquier carácter no alfanumérico por _
    nombre = re.sub(r"[^A-Za-z0-9_]+", "_", nombre)
    # Evitar múltiples guiones bajos
    nombre = re.sub(r"_+", "_", nombre)
    # Quitar _ al inicio/fin
    nombre = nombre.strip("_")
    if not nombre:
        nombre = "col"
    # Evitar que inicie con número
    if re.match(r"^\d", nombre):
        nombre = "col_" + nombre
    return nombre.lower()


def detectar_headers(path_excel: str) -> List[str]:
    # Leer solamente encabezados (primera fila). Pandas ya trae columnas en df.columns
    try:
        df = pd.read_excel(path_excel, sheet_name=0, header=0)
    except Exception as e:
        print(f"[ERROR] No se pudo leer el archivo: {e}", file=sys.stderr)
        print("Sugerencia: asegúrate de tener 'openpyxl' instalado para .xlsx => pip install openpyxl", file=sys.stderr)
        raise
    headers = [str(c) if c is not None else '' for c in list(df.columns)]
    return headers


def inferir_tipos_sql(path_excel: str, sample_rows: int = 2000) -> Tuple[List[str], List[str]]:
    """
    Lee el Excel y devuelve:
      - headers originales (en el orden del archivo)
      - lista de tipos SQL sugeridos por columna (mismo orden)

    Heurística simple:
      - datetime64 => DATETIME (o DATE si no hay componente de hora)
      - numérico entero => INT
      - numérico con decimales => DECIMAL(15,2)
      - texto => VARCHAR(255)
      - Si parece fecha al parsear con pandas (>= 70% parseable) => DATE/DATETIME
    """
    try:
        df = pd.read_excel(path_excel, sheet_name=0, header=0)
    except Exception as e:
        print(f"[ERROR] No se pudo leer el archivo: {e}", file=sys.stderr)
        print("Sugerencia: asegúrate de tener 'openpyxl' instalado para .xlsx => pip install openpyxl", file=sys.stderr)
        raise

    if sample_rows and len(df) > sample_rows:
        df_sample = df.head(sample_rows)
    else:
        df_sample = df

    headers = [str(c) if c is not None else '' for c in list(df_sample.columns)]
    tipos: List[str] = []

    for col in df_sample.columns:
        s = df_sample[col]
        s_nonnull = s.dropna()
        chosen = "VARCHAR(255)"

        # Si no hay datos, dejar VARCHAR
        if s_nonnull.empty:
            tipos.append(chosen)
            continue

        colname_l = str(col).lower()

        # Detectar datetime por dtype directamente
        if pd.api.types.is_datetime64_any_dtype(s):
            # Ver si hay componente de hora
            try:
                has_time = any(getattr(v, 'hour', 0) != 0 or getattr(v, 'minute', 0) != 0 or getattr(v, 'second', 0) != 0 for v in s_nonnull)
            except Exception:
                has_time = False
            chosen = "DATETIME" if has_time else "DATE"
            tipos.append(chosen)
            continue

        # Intentar parsear fecha desde texto SOLO si el nombre sugiere fecha o hay separadores
        looks_like_date_col = ('fecha' in colname_l) or ('fech' in colname_l)
        if not pd.api.types.is_numeric_dtype(s):
            try:
                # Heurística rápida: porcentaje de strings con '/' o '-' o ':'
                s_str = s_nonnull.astype(str)
                sep_ratio = s_str.str.contains(r"[/\-:]", regex=True).mean() if len(s_str) else 0.0
                if looks_like_date_col or sep_ratio >= 0.6:
                    parsed = pd.to_datetime(s_str, errors='coerce', dayfirst=True)
                    ratio_dates = parsed.notna().mean() if len(parsed) else 0.0
                    if ratio_dates >= 0.7:
                        # Detectar si hay horas
                        has_time = False
                        try:
                            has_time = any(getattr(v, 'hour', 0) != 0 or getattr(v, 'minute', 0) != 0 or getattr(v, 'second', 0) != 0 for v in parsed.dropna())
                        except Exception:
                            has_time = False
                        chosen = "DATETIME" if has_time else "DATE"
                        tipos.append(chosen)
                        continue
            except Exception:
                pass

        # Numérico
        if pd.api.types.is_numeric_dtype(s):
            # Si es float, ver si todos son enteros
            try:
                as_float = s_nonnull.astype(float)
                is_int_like = (as_float.round(0) == as_float).mean() >= 0.95
                if is_int_like:
                    chosen = "INT"
                else:
                    chosen = "DECIMAL(15,2)"
                tipos.append(chosen)
                continue
            except Exception:
                pass

        # Si es texto, intentar numérico
        try:
            as_num = pd.to_numeric(s_nonnull.astype(str).str.replace(',', '', regex=False), errors='coerce')
            ratio_num = as_num.notna().mean()
            if ratio_num >= 0.7:
                is_int_like = (as_num.dropna().round(0) == as_num.dropna()).mean() >= 0.95 if as_num.notna().any() else False
                chosen = "INT" if is_int_like else "DECIMAL(15,2)"
                tipos.append(chosen)
                continue
        except Exception:
            pass

        # Por defecto
        tipos.append(chosen)

    return headers, tipos


def build_create_sql(nombre_tabla: str, headers: List[str], tipos: List[str] | None = None) -> str:
    cols = []
    for idx, h in enumerate(headers):
        col = normalizar_col(h)
        tipo = (tipos[idx] if tipos and idx < len(tipos) else "VARCHAR(255)")
        cols.append(f"`{col}` {tipo}")
    sql = (
        f"CREATE TABLE `{nombre_tabla}` (\n"
        f"  id INT AUTO_INCREMENT PRIMARY KEY,\n"
        f"  " + ",\n  ".join(cols) + "\n"
        f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    )
    return sql


def main() -> None:
    parser = argparse.ArgumentParser(description='Leer encabezados de un Excel y generar campos para tabla SQL')
    parser.add_argument('-f', '--file', default='DEVOLUCIONES POR CLIENTE.xlsx', help='Ruta del archivo Excel (.xlsx/.xls)')
    parser.add_argument('-t', '--table', default='devoluciones_por_cliente', help='Nombre sugerido de la tabla SQL')
    parser.add_argument('--infer-types', action='store_true', help='Inferir tipos SQL a partir de los datos (INT, DECIMAL, DATE, etc.)')
    parser.add_argument('--sample-rows', type=int, default=2000, help='Número de filas a muestrear para la inferencia de tipos')
    args = parser.parse_args()

    path_excel = args.file
    if not os.path.isabs(path_excel):
        path_excel = os.path.join(os.path.dirname(__file__), path_excel)

    if not os.path.exists(path_excel):
        print(f"[ERROR] No se encontró el archivo: {path_excel}")
        print("Colócalo en el mismo folder o indica la ruta con -f.")
        sys.exit(1)

    if args.infer_types:
        headers, tipos = inferir_tipos_sql(path_excel, sample_rows=args.sample_rows)
    else:
        headers = detectar_headers(path_excel)
        tipos = None
    norm = [normalizar_col(h) for h in headers]

    print("\n=== Encabezados detectados (originales) ===")
    for i, h in enumerate(headers, 1):
        print(f"{i:02d}. {h}")

    print("\n=== Nombres normalizados (sugeridos para SQL) ===")
    for i, h in enumerate(norm, 1):
        print(f"{i:02d}. {h}")

    print("\n=== JSON de campos (normalizados) ===")
    print(json.dumps(norm, ensure_ascii=False, indent=2))

    titulo = "\n=== CREATE TABLE sugerido (con tipos inferidos) ===" if args.infer_types else "\n=== CREATE TABLE sugerido (tipos genéricos) ==="
    print(titulo)
    print(build_create_sql(args.table, headers, tipos))


if __name__ == '__main__':
    main()
