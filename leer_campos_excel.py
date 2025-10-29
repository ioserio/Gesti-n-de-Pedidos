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
from typing import List

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


def build_create_sql(nombre_tabla: str, headers: List[str]) -> str:
    cols = []
    for h in headers:
        col = normalizar_col(h)
        # Por defecto como VARCHAR(255); luego puedes ajustar tipos
        cols.append(f"`{col}` VARCHAR(255)")
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
    args = parser.parse_args()

    path_excel = args.file
    if not os.path.isabs(path_excel):
        path_excel = os.path.join(os.path.dirname(__file__), path_excel)

    if not os.path.exists(path_excel):
        print(f"[ERROR] No se encontró el archivo: {path_excel}")
        print("Colócalo en el mismo folder o indica la ruta con -f.")
        sys.exit(1)

    headers = detectar_headers(path_excel)
    norm = [normalizar_col(h) for h in headers]

    print("\n=== Encabezados detectados (originales) ===")
    for i, h in enumerate(headers, 1):
        print(f"{i:02d}. {h}")

    print("\n=== Nombres normalizados (sugeridos para SQL) ===")
    for i, h in enumerate(norm, 1):
        print(f"{i:02d}. {h}")

    print("\n=== JSON de campos (normalizados) ===")
    print(json.dumps(norm, ensure_ascii=False, indent=2))

    print("\n=== CREATE TABLE sugerido (tipos genéricos) ===")
    print(build_create_sql(args.table, headers))


if __name__ == '__main__':
    main()
