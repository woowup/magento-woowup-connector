<?php
namespace MagentoWoowUpConnector\Clients;

use MagentoWoowUpConnector\Interfaces\ClientInterface;

class SoapClientV2 extends SoapClientAbstract implements ClientInterface
{
    const CONNECTION_TIMEOUT = 300; //5min
    const ID_FIELD = 'sku';

    protected $sessionId;
    protected $client;
    protected $variations;
    protected $productsInfo;
    protected $customersInfo;
    protected $connectionTime;
    protected $config;

    function __construct(array $config)
    {
        $this->sessionId = null;
        $this->client = null;
        $this->connectionTime = null;
        $this->config = $config;
        $this->variations = [];
        $this->productsInfo = [];
        $this->customersInfo = [];

        // convierto los PHP Errors a excepciones
        set_error_handler([$this, 'phpErrorHandler']);

        $this->connect();
    }

    public function phpErrorHandler($errno, $message, $file, $line)
    {
        if (!(error_reporting() & $errno)) {
            // Este código de error no está incluido en error_reporting
            return;
        }

        throw new \ErrorException($message, 0, $errno, $file, $line);
    }

    public function setStore($storeId)
    {
        $this->config['store_id'] = $storeId;
    }

    protected function connect()
    {
        $this->retryCall(function () {
            $opts = array(
                'http'=>array(
                    'user_agent' => 'PHPSoapClient'
                )
            );

            $context = stream_context_create($opts);
            $this->client = new \SoapClient($this->config['host'] . "/api/v2_soap/?wsdl", [
                'stream_context' => $context,
                'cache_wsdl' => WSDL_CACHE_NONE
            ]);
            $this->sessionId = $this->client->login($this->config['apiuser'], $this->config['apikey']);
            $this->connectionTime = time();
        });
    }

    protected function checkReconnect()
    {
        if (is_null($this->connectionTime) || (time() - $this->connectionTime > self::CONNECTION_TIMEOUT)) {
            $this->connect();
        }
    }

    public function getCustomerInfo($customerId)
    {
        if (!isset($this->customersInfo[$customerId])) {
            $this->checkReconnect();

            try {
                $customerInfo = $this->retryCall(function () use ($customerId) {
                    return $this->client->customerCustomerInfo($this->sessionId, $customerId);
                });

                $hasAddress = (isset($customerInfo->default_shipping) && !empty($customerInfo->default_shipping)) || (isset($customerInfo->default_billing) && !empty($customerInfo->default_billing));
                $customerAddress = null;

                if ($customerInfo && $hasAddress) {
                    $addressId = isset($customerInfo->default_billing) ? $customerInfo->default_billing : $customerInfo->default_shipping;

                    $customerAddress = $this->retryCall(function () use ($addressId) {
                        return json_decode(json_encode($this->client->call($this->sessionId, 'customer_address.info', $addressId)));
                    });
                }

                $customerInfo->addressInfo = $customerAddress;

                $this->customersInfo[$customerId] = $customerInfo;
            } catch (\SoapFault $e) {
                \LogService::save('mg-err', "error al buscar la info del customer " . $customerId . " | " . $e->getMessage(), $e);
                return null;
            }
        }

        return $this->customersInfo[$customerId];
    }

    public function findCategories()
    {
        $this->checkReconnect();

        return $this->retryCall(function () {
            return $this->client->catalogCategoryTree($this->sessionId);
        });
    }

    public function findCategory($id)
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($id) {
            return $this->client->catalogCategoryInfo($this->sessionId, $id);
        });

        return $response;
    }

    public function findOrderInfo($id)
    {
        $this->checkReconnect();

        return $this->retryCall(function () use ($id) {
            return $this->client->salesOrderInfo($this->sessionId, $id);
        });
    }

    public function findProductInfo($id, $field = self::ID_FIELD)
    {
        if (!isset($this->productsInfo[$id])) {
            try {
                $this->checkReconnect();

                $this->productsInfo[$id] = $this->retryCall(function () use ($id, $field) {
                    return $this->client->catalogProductInfo($this->sessionId, $id, null, null, $field);
                });
            } catch (\SoapFault $e) {
                \LogService::save('mg-err', "error al buscar la info del producto " . $id . " | " . $e->getMessage(), $e);
                return null;
            }
        }

        return $this->productsInfo[$id];
    }

    public function findProductAttributeSets()
    {
        $this->checkReconnect();

        return $this->retryCall(function () {
            return $this->client->catalogProductAttributeSetList($this->sessionId);
        });
    }

    public function findProductAttributes($id)
    {
        $this->checkReconnect();

        return $this->retryCall(function () use ($id) {
            return $this->client->catalogProductAttributeList($this->sessionId, $id);
        });
    }

    public function findProductAditionalAttributes($setId, $type = 'simple')
    {
        $this->checkReconnect();

        return $this->retryCall(function () use ($type, $setId) {
            return $this->client->catalogProductListOfAdditionalAttributes($this->sessionId, $type, $setId);
        });
    }

    public function findProductCustomOptions($id)
    {
        $this->checkReconnect();

        return $this->retryCall(function () use ($id) {
            return $this->client->catalogProductCustomOptionList($this->sessionId, $id);
        });
    }

    public function findOrders($from, $to)
    {
        $this->checkReconnect();

        $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;

        return $this->retryCall(function () use ($from, $to, $storeId) {
            // possible statuses: pending, processing, complete, cancelled, closed, onhold
            $filter = [];

            if ($storeId) {
                $filter['filter'] = [
                    ['key' => 'store_id', 'value' => $storeId]
                ];
            }

            $filter += [
                'complex_filter' => array(
                    array(
                        'key' => 'created_at',
                        'value' => array('key' => 'from', 'value' => $from)
                    ),
                    array(
                        'key' => 'CREATED_AT',
                        'value' => array('key' => 'to', 'value' => $to)
                    ),
                )
            ];

            return $this->client->salesOrderList($this->sessionId, $filter);
        });
    }

    public function findCustomers($from, $to = null, $new = false)
    {
        $this->checkReconnect();
        $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;

        return $this->retryCall(function () use ($from, $to, $new, $storeId) {
            $field = $new ? 'created_at' : 'updated_at';

            $filter = [
                'complex_filter' => [
                    [
                        'key' => $field,
                        'value' => ['key' => 'from', 'value' => $from]
                    ]
                ]
            ];

            if (!is_null($to)) {
                $filter['complex_filter'][] = [
                    'key' => strtoupper($field),
                    'value' => ['key' => 'to', 'value' => $to]
                ];
            }

            if ($storeId) {
                $filter['complex_filter'][] = [
                    'key' => 'store_id',
                    'value' => array('key' => '=', 'value' => $storeId)
                ];
            }

            return $this->client->customerCustomerList($this->sessionId, $filter);
        });
    }

    /**
    *   Los nombres del complex filter aceptan solo "un" atributo unico, pero si le
    *   pasas el mismo en mayúsculas y en minusculas va genial
    *   @see https://stackoverflow.com/questions/14579639/combined-complex-filter-for-ranges
    */
    public function listProducts($from = null, $to = null)
    {
        try {
            $this->checkReconnect();

            $filter = array(
                'complex_filter' => array(
                    array(
                        'key' => 'UPDATED_AT',
                        'value' => array('key' => 'from', 'value' => $from)
                    ),
                    array(
                        'key' => 'updated_at',
                        'value' => array('key' => 'to', 'value' => $to)
                    )
                )
            );

            $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;

            $response = $this->retryCall(function () use ($filter, $storeId) {
                return $this->client->catalogProductList($this->sessionId, $filter, $storeId);
            });

            return $response;
        } catch (\SoapFault $e) {
            \LogService::save('mg-err', "error al buscar las tiendas | " . $e->getMessage(), $e);
            return null;
        }
    }

    public function getStockForProduct($id)
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () use ($id) {
                return $this->client->catalogInventoryStockItemList($this->sessionId, [$id]);
            });

            return $response;
        } catch (\SoapFault $e) {
            \LogService::save('mg-err', "error al buscar stock para el producto sku #{$id} | " . $e->getMessage(), $e);
            return null;
        }
    }

    public function getMediaForProduct($id)
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () use ($id) {
                return $this->client->catalogProductAttributeMediaList($this->sessionId, $id, null, self::ID_FIELD);
            });

            return $response;
        } catch (\SoapFault $e) {
            \LogService::save('mg-err', "error al buscar imagenes para el producto sku #{$id} | " . $e->getMessage(), $e);
            return null;
        }
    }

    public function getStores()
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () {
                return $this->client->storeList($this->sessionId);
            });

            return $response;
        } catch (\SoapFault $e) {
            \LogService::save('mg-err', "error al buscar las tiendas | " . $e->getMessage(), $e);
            return null;
        }
    }
}
