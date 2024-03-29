<?php

namespace MagentoWoowUpConnector;

use Exception;

class WoowUpHelper
{

    private $_woowupClient;
    private $_logger;

    private $_woowupStats = [
        'customers' => [
            'created' => 0,
            'updated' => 0,
            'failed'  => [],
        ],
        'orders'    => [
            'created'    => 0,
            'updated'    => 0,
            'duplicated' => 0,
            'failed'     => [],
        ],
        'products'  => [
            'created' => 0,
            'updated' => 0,
            'failed'  => [],
        ],
    ];

    public function __construct($woowupClient, $logger)
    {
        $this->_woowupClient = $woowupClient;
        $this->_logger = $logger;
    }

    public function upsertOrder($order, $update)
    {
        try {
            $this->_woowupClient->purchases->create($order);
            $this->_logger->info("[Purchase] {$order['invoice_number']} Created Successfully");
            $this->_woowupStats['orders']['created']++;
            return true;
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response = json_decode($e->getResponse()->getBody(), true);
                switch ($response['code']) {
                    case 'user_not_found':
                        $this->_logger->info("[Purchase] {$order['invoice_number']} Error: customer not found");
                        $this->_woowupStats['orders']['failed'][] = $order;
                        return false;
                    case 'duplicated_purchase_number':
                        $this->_logger->info("[Purchase] {$order['invoice_number']} Duplicated");
                        $this->_woowupStats['orders']['duplicated']++;
                        return $this->updateOrder($update, $order);
                    default:
                        $errorCode    = $response['code'];
                        if (isset($response['payload']['errors']) && isset($response['payload']['errors'][0])) {
                            $errorMessage = $response['payload']['errors'][0];
                        } else {
                            $errorMessage = $response['message'];
                        }
                        break;
                }
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->_logger->info("[Purchase] {$order['invoice_number']} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->_woowupStats['orders']['failed'][] = $order;

            return false;
        }
    }

    public function upsertCustomer($customer)
    {
        $customerIdentity = [
            'email'    => isset($customer['email']) ? $customer['email'] : '',
            'document' => isset($customer['document']) ? $customer['document'] : '',
        ];
        try {
            if (!$this->_woowupClient->multiusers->exist($customerIdentity)) {
                $this->_woowupClient->users->create($customer);
                $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Created Successfully");
                $this->_woowupStats['customers']['created']++;
            } else {
                $this->_woowupClient->multiusers->update($customer);
                $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Updated Successfully");
                $this->_woowupStats['customers']['updated']++;
            }
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response     = json_decode($e->getResponse()->getBody(), true);
                $errorCode    = $response['code'];
                if (isset($response['payload']['errors']) && isset($response['payload']['errors'][0])) {
                    $errorMessage = $response['payload']['errors'][0];
                } else {
                    $errorMessage = $response['message'];
                }
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->_woowupStats['customers']['failed'][] = $customer;

            return false;
        }

        return true;
    }

    /**
     * Crea/Actualiza un producto en WoowUp
     * @param  array $product Producto en formato WoowUp
     * @return boolean        true: producto actualizado/creado con éxito, false: error
     */
    public function upsertProduct($product)
    {
        try {
            $this->_woowupClient->products->update($product['sku'], $product);
            $this->_logger->info("[Product] {$product['sku']} Updated Successfully");
            $this->_woowupStats['products']['updated']++;
            return true;
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response = json_decode($e->getResponse()->getBody(), true);
                if ($e->getResponse()->getStatusCode() == 404) {
                    // no existe el producto
                    try {
                        $this->_woowupClient->products->create($product);
                        $this->_logger->info("[Product] {$product['sku']} Created Successfully");
                        $this->_woowupStats['products']['created']++;
                        return true;
                    } catch (\Exception $e) {
                        $this->_logger->info("[Product] {$product['sku']} Error: Code '" . $e->getCode() . "', Message '" . $e->getMessage() . "'");
                        $this->_woowupStats['products']['failed'][] = $product;
                    }
                } else {
                    $errorCode    = $response['code'];
                    if (isset($response['payload']['errors']) && isset($response['payload']['errors'][0])) {
                        $errorMessage = $response['payload']['errors'][0];
                    } else {
                        $errorMessage = $response['message'];
                    }
                }
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->_logger->info("[Product] {$product['sku']} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->_woowupStats['products']['failed'][] = $product;
            return false;
        }
    }

    public function getApiStats()
    {
        return $this->_woowupStats;
    }

    public function resetFailed($entity = null)
    {
        if ($entity === null) {
            foreach ($this->_woowupStats as $entityKey => $stats) {
                $this->_woowupStats[$entityKey]['failed'] = [];
            }
        } elseif (isset($this->_woowupStats[$entity])) {
            $this->_woowupStats[$entity]['failed'] = [];
        } else {
            $this->_logger->info("Unexistent entity $entity");
        }
    }

    public function searchProducts($search = [])
    {
        $page = 0;
        $limit = 100;

        do {
            $this->_logger->info("Page $page");
            try {
                $products = $this->_woowupClient->products->search($search, $page, $limit);
                foreach ($products as $product) {
                    yield $product;
                }
            } catch (\Exception $e) {
                if (method_exists($e, 'getResponse')) {
                    $response     = json_decode($e->getResponse()->getBody(), true);
                    $errorCode    = $response['code'];
                    if (isset($response['payload']['errors']) && isset($response['payload']['errors'][0])) {
                        $errorMessage = $response['payload']['errors'][0];
                    } else {
                        $errorMessage = $response['message'];
                    }
                } else {
                    $errorCode    = $e->getCode();
                    $errorMessage = $e->getMessage();
                }
                $this->_logger->info("[Search products] Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            }

            $page++;
        } while (!empty($products));
    }

    private function updateOrder($update, $order)
    {
        $invoice_number = $order['invoice_number'] ?? 'UNKNOWN_INVOICE_NUMBER';
        try {
            if ($update) {
                $this->_woowupClient->purchases->update($order);
                $this->_logger->info("[Purchase] $invoice_number Updated Successfully");
                $this->_woowupStats['orders']['updated']++;
            }
        } catch (Exception $e) {
            if (!method_exists($e, 'getResponse')) {
                return false;
            }

            $response = json_decode($e->getResponse()->getBody(), true);
            if ($response['code'] === 'user_not_found') {
                $this->_logger->info("[Purchase] $invoice_number is already associated with another Customer. Skipping Order...");
                return true;
            }

            $this->_logger->info("[Purchase] $invoice_number Error: Code '" . $e->getCode() . "', Message '" . $e->getMessage() . "'");
            return false;
        }
        return true;
    }
}
