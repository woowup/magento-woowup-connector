<?php
namespace MagentoWoowUpConnector\Clients;

abstract class SoapClientAbstract
{
    /**
     * Base que se eleva a la cantidad de intentos que lleva para obtener
     * el tiempo en segundos de espera hasta la próxima ejecución.
     * Ej: 2, 4, 8, 16, ...
     */
    const EXPONENTIAL_RETRY_BASE = 2;

    /**
     * Cantidad máxima de intentos
     */
    const MAX_INTENTS = 3;

    protected function retryCall($callback)
    {
        $intents = 0;
        while ($intents < self::MAX_INTENTS) {
            try {
                return $callback();
            } catch (\SoapFault $e) {
                $intents++;

                if(strpos($e->getMessage(), 'not exists.') === false){
                    throw $e;
                }

                $secondsToWait = pow(self::EXPONENTIAL_RETRY_BASE, $intents);
                echo "Intento $intents fallido. Reintentando en $secondsToWait segundos".PHP_EOL;
                sleep(pow($secondsToWait));

                if ($intents == self::MAX_INTENTS) {
                    throw $e;
                }
            }
        }

        return null;
    }
}