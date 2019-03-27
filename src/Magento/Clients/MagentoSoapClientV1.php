<?php
namespace WoowUpConnectors\Magento\Clients;

class MagentoSoapClientV1 extends MagentoSoapClientAbstract implements MagentoClientInterface
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
            $this->client = new \SoapClient($this->config['host'] . "/api/soap/?wsdl", [
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
                    return json_decode(json_encode($this->client->call($this->sessionId, 'customer.info', $customerId)));
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
                $this->logger->info("error al buscar la info del customer " . $customerId . " | " . $e->getMessage());
                return null;
            }
        }

        return $this->customersInfo[$customerId];
    }

    public function findCategories()
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () {
            return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_category.tree')));
        });

        return $response;
    }

    public function findCategory($id)
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($id) {
            return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_category.info', $id)));
        });

        return $response;
    }

    public function findProductAditionalAttributes($setId, $type = 'simple')
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($type, $setId) {
            return json_decode(json_encode($this->client->call($this->sessionId, 'product.listOfAdditionalAttributes', [$type, $setId])));
        });

        return $response;
    }

    public function findProductCustomOptions($id)
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($id) {
            return json_decode(json_encode($this->client->call($this->sessionId, 'product_custom_option.list', $id)));
        });

        return $response;
    }

    public function findOrderInfo($id)
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($id) {
            return json_decode(json_encode($this->client->call($this->sessionId, 'sales_order.info', $id)));
        });

        return $response;
    }

    public function findProductInfo($id, $field = self::ID_FIELD)
    {
        if (!isset($this->productsInfo[$id])) {
            try {
                $this->checkReconnect();

                $attributes = new \stdclass();
                $attributes->attributes = $this->variations;

                $response = $this->retryCall(function () use ($id, $field) {
                    return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_product.info', [$id, null, null, $field])));
                });

                $this->productsInfo[$id] = $response;
            } catch (\SoapFault $e) {
                $this->logger->info("error al buscar la info del producto " . $id . " | " . $e->getMessage());
                return null;
            }
        }

        return $this->productsInfo[$id];
    }

    public function findProductAttributeSets()
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () {
            return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_product_attribute_set.list')));
        });

        return $response;
    }

    public function findProductAttributes($id)
    {
        $this->checkReconnect();

        $response = $this->retryCall(function () use ($id) {
            return json_decode(json_encode($this->client->call(
                $this->sessionId,
                "product_attribute.list",
                array(
                    $id
                )
            )));
        });

        return $response;
    }


    public function findOrders($from, $to)
    {
        $this->checkReconnect();
        // possible statuses: pending, processing, complete, cancelled, closed, onhold

        $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;
        $statuses = isset($this->config['status']) && !empty($this->config['status']) ? $this->config['status'] : null;

        $response = $this->retryCall(function () use ($from, $to, $storeId, $statuses) {
            $filter = [];

            if ($storeId) {
                $filter['store_id'] = ['=' => $storeId];
            }

            if ($statuses) {
                $filter['status'] = ['in' => $statuses];
            }

            $filter += [
                'created_at' => ['gteq' => $from],
                'CREATED_AT' => ['lteq' => $to]
            ];

            return json_decode(json_encode($this->client->call($this->sessionId, 'order.list', [$filter])));
        });

        return $response;
    }

    public function findCustomers($from, $to = null, $new = false)
    {

        try {
            $this->checkReconnect();
            $field = $new ? 'created_at' : 'updated_at';
            $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;

            $filter = [
                'complex_filter' => []
            ];

            if ($from) {
                $filter['complex_filter'][$field] = [
                    'from' => $from
                ];
            }

            if ($to) {
                $filter['complex_filter'][$field] = [
                    'to' => $to
                ];
            }

            if ($storeId) {
                $filter['store_id'] = ['=' => $storeId];
            }

            $response = $this->retryCall(function () use ($filter) {
                return json_decode(json_encode($this->client->call($this->sessionId, 'customer.list', $filter)));
            });

            return $response;
        } catch (\SoapFault $e) {
            throw new \Exception("error al buscar el listado de clientes | " . $e->getMessage(), 1);
        }
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

            $filter = [
                'updated_at' => ['gteq' => $from],
                'UPDATED_AT' => ['lteq' => $to]
            ];

            $storeId = isset($this->config['store_id']) && !empty($this->config['store_id']) ? $this->config['store_id'] : null;

            $response = $this->retryCall(function () use ($filter, $storeId) {
                $parameters = ['filters' => $filter];

                if ($storeId) {
                    $parameters['store_id'] = $storeId;
                }

                return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_product.list', $parameters)));
            });

            return $response;
        } catch (\SoapFault $e) {
            $this->logger->info("error al buscar la info del listado de productos | " . $e->getMessage());
            return null;
        }
    }

    public function getStockForProduct($id)
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () use ($id) {
                return json_decode(json_encode($this->client->call($this->sessionId, 'cataloginventory_stock_item.list', $id)));
            });

            return $response;
        } catch (\SoapFault $e) {
            $this->logger->info("error al buscar stock para el producto #{$id} | " . $e->getMessage());
            return null;
        }
    }

    public function getMediaForProduct($id)
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () use ($id) {
                return json_decode(json_encode($this->client->call($this->sessionId, 'catalog_product_attribute_media.list', [$id, null, self::ID_FIELD])));
            });

            return $response;
        } catch (\SoapFault $e) {
            $this->logger->info("error al buscar imagenes para el producto #{$id} | " . $e->getMessage());
            return null;
        }
    }

    public function getStores()
    {
        try {
            $this->checkReconnect();

            $response = $this->retryCall(function () {
                return json_decode(json_encode($this->client->call($this->sessionId, 'store.list')));
            });

            return $response;
        } catch (\SoapFault $e) {
            $this->logger->info("error al buscar las tiendas | " . $e->getMessage());
            return null;
        }
    }
}
