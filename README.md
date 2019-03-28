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

Dentro del directorio `vendor` tendrás instaladas todas las dependencias, y dentro del directorio `woowup` estará instalado el paquete:

![vendor](https://i.imgur.com/LztT4kT.png)
