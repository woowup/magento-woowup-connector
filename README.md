<img src="https://admin.woowup.com/themes/woowup/img/web/logo.png" alt="drawing" width="200"/>

# magento-woowup-connector
**Sincroniza tu tienda Magento con tu cuenta de WoowUp**

Esta librería obtiene datos de tu tienda Magento utilizando el servicio SOAP y los inserta en WoowUp utilizando la API REST.

# Instalación

## Requerimientos

* [PHP 7.0+](https://www.php.net/manual/en/install.php)
* [Composer](http://getcomposer.org/)
* Credenciales para acceder vía SOAP a tu tienda Magento ([¿Cómo obtenerlas?](https://docs.woowup.com/magento/magento-connect-account))
* Una cuenta WoowUp

## Instalación

Agrega el paquete a tu `composer.json` dentro del directorio de trabajo:
```json
{
  "require": {
    "woowup/magento-woowup-connector": "dev-master"
  },
  "minimum-stability": "dev"
}

```

Actualiza tus paquetes de Composer desde la terminal de comandos:

```
> composer update
```

Dentro del directorio `vendor` tendrás instaladas todas las dependencias, y dentro del directorio `woowup` estará instalado el paquete junto con el [cliente PHP de WoowUp](https://github.com/woowup/woowup-php-client):

![vendor](https://i.imgur.com/LztT4kT.png)

## Ejemplo
magento_woowup_example.php
```php
<?php

require_once 'vendor/autoload.php';

use MagentoWoowUpConnector\MagentoSOAP as SoapConnector;
use Monolog\Handler\StreamHandler as StreamHandler;
use Monolog\Logger as Logger;
use WoowUp\Client as WoowUpClient;

// Instancia del Logger en un canal 'my-channel'
$logger = new Logger('my-channel');
// Redireccionamos la salida del logger a la salida estandar (consola)
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
// Logeamos un mensaje
$logger->info("Esto es un ejemplo del conector de Magento con WoowUp");

// Instancia del cliente de WoowUp
// Parámetros: apikey, host, version
$woowUpApiKey  = 'xxxxxxxxxxxxxxxxxx';
$woowUpHost    = "https://api.woowup.com";
$woowUpVersion = "apiv3";
$woowUpClient  = new WoowUpClient($woowUpApiKey, $woowUpHost, $woowUpVersion);

// Configuración de magento
$magentoConfig = [
    // URL de la tienda (obligatorio)
    'host'       => 'https://my-magento-store.com',
    // Magento Api User (obligatorio)
    'apiuser'    => 'my-app-user',
    // Magento Api key (obligatorio)
    'apikey'     => 'my-app-key',
    // Version de Magento 1 ó 2 (obligatorio)
    'version'    => 1,
    // Estados de venta para descargar (array opcional, default: complete) 
    'status'     => [
        'complete',
        'processing',
    ],
    // Stores ids para descargar (opcional, si no se incluye se consideran todas)
    'store_id'   => null,
    // Nombre para la sucursal (opcional, default: 'MAGENTO')
    'branchName' => 'myMagentoBranch',
    // Variaciones de producto a considerar (array opcional, si no se incluye no se consideran variaciones)
    'variations' => [
        'talle',
        'color',
    ],
    // Booleana que indica si queremos sincronizar categorias (opcional, default: false)
    'categories' => true,
];

// Instancia del conector
$connector = new SoapConnector($magentoConfig, $logger, $woowUpClient);

// Crear/actualizar en WoowUp clientes actualizados en Magento en los últimos 5 días
$connector->importCustomers(5);

// Crear en WoowUp ventas en los status indicados creadas en Magento en los últimos 20 días
$connector->importOrders(20);

// Crear/actualizar en WoowUp ventas creadas en los últimos 30 días
$connector->importOrders(30, true);

// Crear/actualizar en WoowUp productos creados en los últimos 2 meses
$connector->importProducts(2);
```
