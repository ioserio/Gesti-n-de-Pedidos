# Integración con perudevs.com

Este proyecto consulta DNI y RUC usando `perudevs.com`.

## Variables de entorno

Configura en Windows PowerShell (XAMPP):

```powershell
$env:PERUDEVS_TOKEN = "<TU_TOKEN_PERUDEVS>"
# Opcional si los endpoints del proveedor difieren a los tentativos:
$env:PERUDEVS_DNI_URL = "https://api.perudevs.com/v1/dni/12345678"  # se reemplaza 12345678 dinámicamente
$env:PERUDEVS_RUC_URL = "https://api.perudevs.com/v1/ruc/20123456789" # se reemplaza dinámicamente
$env:PERUDEVS_HEADER  = "Authorization: Bearer" # o "apikey" según documentación
```

También puedes definirlos en `tools_tokens.php` (no recomendado en repos públicos).

## Endpoints locales

- DNI: `tools_dni.php` (POST JSON: `{ "dni": "12345678" }`)
- RUC: `tools_ruc.php` (POST JSON: `{ "ruc": "20123456789" }`)

## Pruebas rápidas

```powershell
curl -X POST -H "Content-Type: application/json" -d '{"dni":"12345678"}' http://localhost/Gesti-n-de-Pedidos/tools_dni.php
curl -X POST -H "Content-Type: application/json" -d '{"ruc":"20123456789"}' http://localhost/Gesti-n-de-Pedidos/tools_ruc.php
```

## Notas

- Se eliminaron todas las referencias al proveedor anterior (decolecta).
- Si la respuesta real de perudevs difiere, comparte un ejemplo JSON y ajustamos el mapeo en `tools_dni.php` y `tools_ruc.php`.
