<?php
// Archivo opcional: define tokens si no puedes usar variables de entorno
// NUNCA subas tokens reales a repos públicos.
return [
  // Proveedor perudevs.com (único activo)
  // Coloca tu token real (NO subir a repos públicos). También puedes definir estas variables vía entorno.
  // Ejemplos de variables de entorno aceptadas: PERUDEVS_TOKEN, PERUDEVS_DNI_URL, PERUDEVS_RUC_URL, PERUDEVS_HEADER
  // Si PERUDEVS_DNI_URL o PERUDEVS_RUC_URL están vacíos se usarán defaults tentativos.
  'PERUDEVS_TOKEN'    => 'cGVydWRldnMucHJvZHVjdGlvbi5maXRjb2RlcnMuNjkxOTQwNmFiMzRiYmQ0MjA5ZmZlNzc1', // p.ej: base64 del panel: cGVydWRldnMu...
  'PERUDEVS_DNI_URL'  => 'https://api.perudevs.com/api/v1/dni/complete', // default tentativo: https://api.perudevs.com/v1/dni/{DNI}
  'PERUDEVS_RUC_URL'  => 'https://api.perudevs.com/api/v1/ruc', // default tentativo: https://api.perudevs.com/v1/ruc/{RUC}
  'PERUDEVS_HEADER'   => '',  // ya no usamos header; el token viaja en query key=...
  'PERUDEVS_KEY_PARAM'=> 'key', // nombre del parámetro query que porta el token
  // Config avanzado (opcional): método y nombre de parámetro cuando la URL no incluye {DNI}/{RUC}
  'PERUDEVS_DNI_METHOD' => 'GET',    // 'POST' o 'GET'; si GET y no hay {DNI}, se agrega query ?{param}=valor
  'PERUDEVS_DNI_PARAM'  => 'document', // nombre de campo para DNI en JSON o query
  'PERUDEVS_RUC_METHOD' => 'POST',   // 'POST' o 'GET'
  'PERUDEVS_RUC_PARAM'  => 'ruc'     // nombre de campo para RUC
];
