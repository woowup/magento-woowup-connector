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
    const MAX_INTENTS = 5;

    protected function retryCall($callback)
    {
        $intents = 0;
        while ($intents < self::MAX_INTENTS) {
            try {
                return $callback();
            } catch (\SoapFault $e) {
                $intents++;

                sleep(pow(self::EXPONENTIAL_RETRY_BASE, $intents));

                if ($intents == self::MAX_INTENTS) {
                    throw $e;
                }
            }
        }

        return null;
    }
}
