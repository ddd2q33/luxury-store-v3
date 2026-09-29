<?php

/*
|--------------------------------------------------------------------------
| Datos de la empresa
|--------------------------------------------------------------------------
|
| Se usan en los documentos que ve el cliente: factura, ticket de venta y
| el mensaje de WhatsApp. En el legacy (`oldluxury/config.php:20-26`) eran
| LITERALES en el archivo, no filas de ninguna tabla, asi que no hay de donde
| leerlos ni a donde guardarlos. Por eso viven aqui, en un solo archivo.
|
| >>> SON DATOS DE EJEMPLO. `ejemplo => true` hace que Config y los documentos
| >>> muestren un aviso visible hasta que se cambien por los reales.
|
| Guardarlos editables desde la pantalla de Config exigiria una tabla de
| configuracion, que es una MIGRACION DE DOMINIO: hay que pedirla antes.
|
*/

return [

    'ejemplo' => true,

    'nombre' => 'Luxury Premium',

    'nit' => '901.123.456-7',

    'direccion' => 'Calle 123 #45-67, Bogotá',

    'telefono' => '+57 300 123 4567',

    'email' => 'info@luxurypremium.com',

    'web' => 'www.luxurypremium.com',

    /** Texto de cierre del ticket y de la factura. */
    'gracias' => '¡GRACIAS POR SU COMPRA!',

    /** Paisaje para el avatar de WhatsApp (sin + ni espacios). */
    'pais_whatsapp' => '57',

];
