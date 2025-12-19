# Importar cartera de clientes

Este módulo permite subir un Excel con la cartera de clientes a la tabla `clientes`.

## Requisitos del archivo
- Primera fila: cabeceras con nombres EXACTOS de columnas.
- Soportadas (tabla `clientes`):
  - `Codigo` (int)
  - `Nombre` (varchar)
  - `TipoDocIdentidad` (varchar)
  - `DocIdentidad` (varchar)
  - `Activo` (varchar)
  - `Direccion` (varchar)
  - `CodigoZonaVenta` (int)
  - `DescripcionZonaVenta` (varchar)
  - `LineaCredito` (decimal)
  - `CodigoZonaReparto` (int)
  - `DescripcionZonaReparto` (varchar)
  - `CategoriaCliente` (varchar)
  - `TipoCliente` (varchar)
  - `Distrito` (varchar)
  - `PKID` (int)
  - `IDCategoriaCliente` (int)
  - `IDZonaVenta` (int)
  - `CCC` (varchar)
  - `RUC` (varchar)
  - `TamanoNegocio` (varchar)
  - `MixProductos` (varchar)
  - `MaquinaExhibidora` (varchar)
  - `CortadorEmbutidos` (varchar)
  - `Visicooler` (varchar)
  - `CajaRegistradora` (varchar)
  - `TelefonoPublico` (varchar)

Puedes incluir solo algunas columnas; las faltantes se registran como `NULL`.

## Dedupe / Actualización
- Prioridad de clave para actualizar:
  1. `RUC` (si está presente)
  2. `DocIdentidad`
  3. `Codigo`
- Si no hay ninguna de estas, se inserta un nuevo registro.

## Uso
1. Ve a `Importar` → "Subir Excel de Clientes".
2. Selecciona tu archivo `.xlsx` y opcionalmente marca "Vaciar tabla antes de importar" para un reset total.
3. Envía y espera el resumen de insertados/actualizados.

## Sugerencias
- Evita caracteres extraños en números: el importador limpia comas y no dígitos en campos numéricos.
- Usa formato `decimal` sin separador de miles para `LineaCredito`.
- Mantén UTF-8 en tu archivo para evitar problemas de acentos.
