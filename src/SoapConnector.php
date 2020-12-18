<?php

namespace MagentoWoowUpConnector;

use MagentoWoowUpConnector\Clients\SoapClientV1;
use MagentoWoowUpConnector\Clients\SoapClientV2;
use MagentoWoowUpConnector\WoowUpHelper;

class SoapConnector
{
    const CONNECTION_TIMEOUT = 300; //5min

    const STATUS_COMPLETE = 'complete';

    const PRODUCT_VISIBILITY_NOT_VISIBLE = 1;
    const PRODUCT_VISIBILITY_IN_CATALOG  = 2;
    const PRODUCT_VISIBILITY_IN_SEARCH   = 3;
    const PRODUCT_VISIBILITY_BOTH        = 4;

    const PRODUCT_STATUS_DISABLED = 2;
    const PRODUCT_STATUS_ENABLED  = 1;

    const PRODUCT_TYPE_SIMPLE       = 'simple';
    const PRODUCT_TYPE_CONFIGURABLE = 'configurable';

    const SERVICEUID_FIELD = 'order.customer_email';

    const CUSTOMER_TAG = 'Magento';

    const DEFAULT_BRANCH_NAME = 'MAGENTO';

    const DEFAULT_ORDERS_DOWNLOAD_DAYS = 5;

    const DEFAULT_CATEGORIES_FIELD = 'category_ids';

    const DEFAULT_URL_FIELD = 'url_path';

    protected $sessionId;
    protected $variations = [];
    protected $productsInfo;
    protected $customersInfo;
    protected $connectionTime;
    protected $branchName;
    protected $client;
    protected $logger;
    protected $woowup;
    protected $config;
    protected $categories;
    protected $filters = [];
    protected $categoriesField;
    protected $urlField;

    public function __construct($privateConfig, $connection, array $config, $logger, $woowupClient)
    {
        $this->logger         = $logger;
        $this->sessionId      = null;
        $this->connectionTime = null;
        $this->config         = $this->validateConfig($config);
        $this->variations     = $this->config['variations'];
        $this->branchName     = $this->config['branchName'];
        $this->client         = $this->getApiClient();
        $this->woowup         = new WoowUpHelper($woowupClient, $logger);

        $this->categoriesField = (isset($privateConfig[$connection['app_id']]['categories_field'])) ? $privateConfig[$connection['app_id']]['categories_field'] : self::DEFAULT_CATEGORIES_FIELD;

        $this->urlField = (isset($privateConfig[$connection['app_id']]['url_field'])) ? $privateConfig[$connection['app_id']]['url_field'] : self::DEFAULT_URL_FIELD;

        if (isset($config['app_id']) && isset($privateConfig[$config['app_id']])) {
            $privateConfig = $privateConfig[$config['app_id']];

            if (isset($privateConfig['filters']) && is_array($privateConfig['filters']) && !empty($privateConfig['filters'])) {
                foreach ($privateConfig['filters'] as $filterClass) {
                    $this->addFilter(new $filterClass());
                }
            }
        }
    }

    /**
     * Imports customers updated since an amount of days
     * @param  integer $days [description]
     * @return [type]        [description]
     */
    public function importCustomers(int $days = 5)
    {
        $this->logger->info("Importing customers from $days days");
        $fromDate = date('Y-m-d', strtotime("-$days days"));

        if (!empty($this->config['stores'])) {
            $stores = $this->config['stores'];
        } else {
            $stores = [null];
        }

        foreach ($stores as $store) {
            $this->setStore($store);

            foreach ($this->getCustomers($fromDate) as $customer) {
                $this->woowup->upsertCustomer($customer);
            }
        }

        $stats = $this->woowup->getApiStats();

        if (count($stats['customers']['failed']) > 0) {
            $this->logger->info("Retrying failed customers");
            $failedCustomers = $stats['customers']['failed'];
            $this->woowup->resetFailed('customers');
            foreach ($failedCustomers as $customer) {
                $this->woowup->upsertCustomer($customer);
            }
        }

        $stats = $this->woowup->getApiStats();

        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $stats['customers']['created']);
        $this->logger->info("Updated customers: " . $stats['customers']['updated']);
        $this->logger->info("Failed customers: " . count($stats['customers']['failed']));
    }

    /**
     * Imports orders created since an amount of days, can update existing orders
     * @param  [type]  $days   [description]
     * @param  boolean $update [description]
     * @return [type]          [description]
     */
    public function importOrders($days = null, $update = false, $importing = false)
    {
        if (!$days) {
            $days = self::DEFAULT_ORDERS_DOWNLOAD_DAYS;
        }
        $this->logger->info("Importing orders from $days days");
        $fromDate = date('Y-m-d', strtotime("-$days days"));

        if (!empty($this->config['stores'])) {
            $stores = $this->config['stores'];
        } else {
            $stores = [null];
        }

        foreach ($stores as $store) {
            $this->setStore($store);

            foreach ($this->getOrders($fromDate, $importing) as $order) {
                $this->woowup->upsertCustomer($order['customer']);
                $this->woowup->upsertOrder($order, $update);
            }
        }

        $stats = $this->woowup->getApiStats();

        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $stats['customers']['created']);
        $this->logger->info("Updated customers: " . $stats['customers']['updated']);
        $this->logger->info("Failed customers: " . count($stats['customers']['failed']));
        $this->logger->info("Created orders: " . $stats['orders']['created']);
        $this->logger->info("Duplicated orders: " . $stats['orders']['duplicated']);
        $this->logger->info("Updated orders: " . $stats['orders']['updated']);
        $this->logger->info("Failed orders: " . count($stats['orders']['failed']));
    }

    /**
     * Imports products created since an amount of months
     * @param  int|integer $months [description]
     * @return [type]              [description]
     */
    public function importProducts(int $months = 6)
    {
        $this->logger->info("Importing products from $months months");
        $updatedSkus = [];
        foreach ($this->getProducts($months) as $product) {
            $this->woowup->upsertProduct($product);
            $updatedSkus[] = $product['sku'];
        }

        // Actualizo los que no están más disponibles
        $this->logger->info("Searching unavailable products in woowup");
        $page = 0; $limit = 100;
        foreach ($this->woowup->searchProducts(['with_stock' => true]) as $wuProduct) {
            // Si el producto no está en VTEX lo deshabilito
            if (!in_array($wuProduct->sku, $updatedSkus)) {
                $this->logger->info("Product " . $wuProduct->sku . " no longer available");
                $this->woowup->upsertProduct([
                    'sku'       => $wuProduct->sku,
                    'name'      => $wuProduct->name,
                    'available' => false,
                    'stock'     => 0,
                ]);
            }
        }

        $stats = $this->woowup->getApiStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created products: " . $stats['products']['created']);
        $this->logger->info("Updated products: " . $stats['products']['updated']);
        $this->logger->info("Failed products: " . count($stats['products']['failed']));

    }

    /**
     * Searches customers between two dates and converts them into Woowup's API format
     * @param  [type]  $from [description]
     * @param  [type]  $to   [description]
     * @param  boolean $new  [description]
     * @return [type]        [description]
     */
    public function getCustomers($from, $to = null, $new = false)
    {
        if (is_null($to)) {
            $this->logger->info("Getting customers from $from");
            foreach ($this->client->findCustomers($from . " 00:00:00", null, $new) as $magentoCustomer) {
                $magentoCustomer = $this->getCustomerInfo($magentoCustomer->customer_id);
                if (($customer = $this->buildCustomer($magentoCustomer)) !== null) {
                    yield $customer;
                }
            }
        } else {
            $this->logger->info("Getting customers from $from to $to");
            $i    = 0;
            $date = date('Y-m-d', time() - 60 * 60 * 24 * $i);

            while ($date >= substr($from, 0, 10)) {
                if (is_null($to) || $date <= substr($to, 0, 10)) {
                    $magentoCustomers = $this->client->findCustomers($date . " 00:00:00", $date . " 23:59:59", $new);
                    if (!is_null($magentoCustomers) && is_array($magentoCustomers)) {
                        foreach ($magentoCustomers as $magentoCustomer) {
                            if (($customer = $this->buildCustomer($magentoCustomer)) !== null) {
                                yield $customer;
                            }
                        }
                    }
                }

                $i++;
                $date = date('Y-m-d', time() - 60 * 60 * 24 * $i);
            }
        }
    }

    /**
     * Searches orders in Magento between two dates and converts them to WoowUp's API format
     * @param  [type]  $fromDate  [description]
     * @param  [type]  $toDate    [description]
     * @return [type]             [description]
     */
    public function getOrders($fromDate, $importing = false, $toDate = null)
    {
        // Categorias
        $this->categories = $this->config['categories'] ? $this->getCategories() : [];

        $i             = 0;
        $groupByStatus = [];
        $date          = date('Y-m-d', strtotime($fromDate));
        $toDate        = is_null($toDate) ? date('Y-m-d') : date('Y-m-d', strtotime($toDate));

        $this->logger->info("Searching from {$date} to {$toDate}");
        $message = "Searching for updated purchases since {$date} to {$toDate}.\n";
        $message .= "Downloadable statuses: " . json_encode($this->config['status']) . "\n";

        if (isset($this->config['store_id']) && !empty($this->config['store_id'])) {
            $message .= "Store: " . $this->config['store_id'];
        }

        $this->logger->info($message);

        while ($date <= $toDate) {
            $magentoOrders = $this->client->findOrders($date . " 00:00:00", $date . " 23:59:59");

            $this->logger->info("Orders count for date {$date}: " . count($magentoOrders));

            foreach ($magentoOrders as $magentoOrder) {
                if (!isset($groupByStatus[$magentoOrder->status])) {
                    $groupByStatus[$magentoOrder->status] = 0;
                }
                $groupByStatus[$magentoOrder->status]++;

                if (in_array($magentoOrder->status, $this->config['status']) && (empty($this->config['store_id']) || $magentoOrder->store_id == $this->config['store_id'])) {
                    $buildOrder = $this->buildOrder($magentoOrder, $importing);

                    if ($buildOrder) {
                        yield $buildOrder;
                    }
                }
            }

            $date = date('Y-m-d', strtotime($date . " +1 day"));
        }

        $this->logger->info("Statuses for the account: " . json_encode($this->config['status']));
        $this->logger->info("Downloaded orders by status: " . json_encode($groupByStatus));
    }

    /**
     * Searches products in Magento created since an amount of months or everyone and maps them to WoowUp's API
     * @param  int|integer $months [description]
     * @param  boolean     $all    [description]
     * @return [type]              [description]
     */
    public function getProducts(int $months = 12, $all = false)
    {
        $this->logger->info("Getting products");

        $start = time();

        if ($all) {
            $i    = 1;
            $from = null;
            $to   = null;
        } else {
            /* ahora importo productos desde magento desde 1 año atras, todos los meses */
            $i    = $months;
            $from = date('Y-m-d', strtotime(date('Y-m-d') . " -{$i} months"));
            $to   = date('Y-m-d', strtotime(date('Y-m-d') . " -" . ($i - 1) . " months"));
        }

        // Categorias
        $this->categories = ($this->config['categories']) ? $this->getCategories() : [];

        while ($i > 0) {
            if ($from && $to) {
                $this->logger->info("Searching since {$from} to {$to}");
            }

            $mgProducts = $this->listProducts($from . " 00:00:00", $to . " 23:59:59");
            $total      = count($mgProducts);

            if (empty($mgProducts)) {
                $this->logger->info("No products for the period");
            } else {
                $this->logger->info("Magento products count: " . $total);

                foreach ($mgProducts as $mgProduct) {
                    if (isset($mgProduct->sku) && !empty($mgProduct->sku)) {
                        $skus[] = $mgProduct->sku;

                        $this->logger->info("Sku #{$mgProduct->sku}");
                        $this->logger->info("Searching info in Magento...");
                        $productInfo = $this->findProductInfo($mgProduct->sku);

                        if ($productInfo) {
                            $types = $this->getProductTypes();

                            foreach ($types as $type) {
                                $product = $this->buildProduct($mgProduct->sku, $type, $productInfo);

                                if ($product) {
                                    yield $product;
                                }
                            }
                        }
                    }

                    $this->logger->info("Total " . $total);
                }
            }

            $i--;

            if ($i > 0) {
                $from = date('Y-m-d', strtotime(date('Y-m-d') . " -{$i} months"));
                $to   = date('Y-m-d', strtotime(date('Y-m-d') . " -" . ($i - 1) . " months"));
            }
        }

        $this->logger->info("Search for Magento products Finished");
    }

    /**
     * Adds a filter
     * @param [type] $filter [description]
     */
    public function addFilter($filter)
    {
        $this->filters[] = $filter;

        return true;
    }

    /**
     * Searches customer info in Magento
     * @param  [type] $customerId [description]
     * @return [type]             [description]
     */
    protected function getCustomerInfo($customerId)
    {
        $this->logger->info("Getting info for customer $customerId");
        return $this->client->getCustomerInfo($customerId);
    }

    /**
     * Maps a Magento order to WoowUp's format
     * @param  [type] $magentoOrder [description]
     * @return [type]               [description]
     */
    protected function buildOrder($magentoOrder, $importing = false)
    {
        $this->logger->info("Building order " . $magentoOrder->increment_id);
        $order = [];

        $customer         = null;
        $magentoOrderInfo = $this->client->findOrderInfo($magentoOrder->increment_id);

        // If the order has no customer_id it tries to build it straight from the order info
        if (isset($magentoOrder->customer_id)) {
            $magentoCustomer = $this->client->getCustomerInfo($magentoOrder->customer_id);
            $customer        = $this->buildCustomer($magentoCustomer);
        } elseif (isset($magentoOrder->customer_email) || !empty(trim($magentoOrder->customer_email))) {
            $this->logger->info("customer_id is NULL. Building customer from order");
            $customer = $this->buildCustomerFromOrder($magentoOrder);
        }

        // Find document in payment info
        if (!isset($customer['document']) && isset($magentoOrderInfo->payment) && !empty($magentoOrderInfo->payment)) {
            $payment = $magentoOrderInfo->payment;
            if (isset($payment->additional_information) && isset($payment->additional_information->docNumber) && !empty($payment->additional_information->docNumber)) {
                $customer['document']      = trim($payment->additional_information->docNumber);
                $customer['document_type'] = trim($payment->additional_information->docType);
            }
        }

        if (($customer === null) || (!isset($customer['email']) && !isset($customer['document']))) {
            $this->logger->info("Invalid customer");
            return null;
        }

        $order['invoice_number'] = $magentoOrder->increment_id;
        $order['customer']       = $customer;
        if (isset($customer['document'])) {
            $order['document'] = $customer['document'];
        } else {
            $order['email'] = $customer['email'];
        }

        $order['channel'] = 'web';

        $order['purchase_detail'] = [];
        foreach ($magentoOrderInfo->items as $item) {
            if (!isset($item->sku)) {
                $this->logger->info("Invalid SKU");
                continue;
            }

            $magentoProduct = $this->client->findProductInfo($item->sku);

            $sku = trim($item->sku);
            foreach ($this->filters as $filter) {
                if (method_exists($filter, 'filterSku')) {
                    $sku = $filter->filterSku($sku);
                }
            }

            $imageUrl = $this->_getImageUrl($item->sku, $magentoProduct);

            $product = [
                'sku'          => $sku,
                'product_name' => isset($item->name) ? ucwords(mb_strtolower(trim($item->name))) : '',
                'quantity'     => (int) $item->qty_ordered,
                'unit_price'   => (float) $item->price,
                'variations'   => [],
                'url'          => $this->_getUrl($magentoProduct),
                'image_url'    => $imageUrl ? $imageUrl : '',
            ];

            if ($product['quantity'] == 0) {
                $this->logger->info("Product {$product['sku']} has quantity 0");
                continue;
            }

            if ($product['unit_price'] == 0) {
                $this->logger->info("Product {$product['sku']} has price 0");
                continue;
            }

            foreach ($this->variations as $variation) {
                if (isset($magentoProduct->{$variation})) {
                    $product['variations'][] = [
                        'name'  => ucwords(mb_strtolower($variation)),
                        'value' => $magentoProduct->{$variation},
                    ];
                }
            }
            foreach ($this->filters as $filter) {
                if (method_exists($filter, 'filterVariations')) {
                    $product['variations'] = $filter->filterVariations($product['variations']);
                }
            }

            // TO-DO agregar proceso de categorias
            if (!is_null($product) && $this->config['categories'] && isset($magentoProduct->{$this->categoriesField}) && count($magentoProduct->{$this->categoriesField}) > 0) {
                $product['category'] = $this->buildProductCategory($magentoProduct->{$this->categoriesField});
            }

            $order['purchase_detail'][] = $product;
        }

        // createtime y approvedtime
        $order['createtime']   = $magentoOrder->created_at;
        $order['approvedtime'] = $importing ? $magentoOrder->created_at : date('c');

        // tienda
        $order['branch_name'] = $this->branchName;

        // precios
        $order['prices'] = [
            'gross'    => (float) $magentoOrder->base_subtotal,
            'discount' => (float) abs($magentoOrder->base_discount_amount),
            'tax'      => (float) $magentoOrder->base_tax_amount,
            'shipping' => (float) $magentoOrder->base_shipping_amount,
            'total'    => (float) $magentoOrder->base_subtotal - abs($magentoOrder->base_discount_amount),
        ];

        // payment
        if (isset($payment)) {
            if (isset($payment->additional_information) && !empty($payment->additional_information)) {
                $order['payment'] = $this->buildOrderPayment($payment->additional_information);
            }
            if (($type = $this->getPaymentType($payment->method)) !== null) {
                $order['payment']['type'] = $type;
            }
        }

        // puntos
        foreach ($this->filters as $filter) {
            if (method_exists($filter, 'getPurchasePoints')) {
                $order['points'] = $filter->getPurchasePoints($order);
            }
            if (method_exists($filter, 'getStoreName')) {
                $order['branch_name'] = $filter->getStoreName($magentoOrder);
            }
        }

        return $order;
    }

    /**
     * Builds customer directly from order
     * @param  [type] $magentoOrder [description]
     * @return [type]               [description]
     */
    protected function buildCustomerFromOrder($magentoOrder)
    {
        $customer = [
            'email' => $magentoOrder->customer_email,
        ];

        //
        if (isset($magentoOrder->customer_firstname) && !empty(trim($magentoOrder->customer_firstname))) {
            $customer['first_name'] = ucwords(mb_strtolower(trim($magentoOrder->customer_firstname)));
            if (isset($magentoOrder->customer_middlename) && !empty(trim($magentoOrder->customer_middlename))) {
                $customer['first_name'] .= ' ' . ucwords(mb_strtolower(trim($magentoOrder->customer_middlename)));
            }
        }

        if (isset($magentoOrder->customer_lastname) && !empty(trim($magentoOrder->customer_lastname))) {
            $customer['last_name'] = ucwords(mb_strtolower(trim($magentoOrder->customer_lastname)));
        }

        if (isset($magentoOrder->customer_dob) && !empty(trim($magentoOrder->customer_dob))) {
            $customer['birthdate'] = trim($magentoOrder->customer_dob);
        }

        if (isset($magentoOrder->customer_gender) && !empty(trim($magentoOrder->customer_gender)) && in_array(trim($magentoOrder->customer_gender), array("1", "2"))) {
            $customer['gender'] = ($customer->gender === "1") ? 'M' : (($customer->gender === "2") ? 'F' : null);
        }

        foreach ($this->filters as $filter) {
            if (method_exists($filter, 'getCustomerCustomAttributes')) {
                $customer['custom_attributes'] = $filter->getCustomerCustomAttributes($magentoOrder);
            }
        }

        return $customer;
    }

    /**
     * Returns Magento stores
     * @return [type] [description]
     */
    protected function getStores()
    {
        return $this->client->getStores();
    }

    /**
     * Maps a Magento Customer to WoowUp's API format
     * @param  [type] $magentoCustomer [description]
     * @return [type]                  [description]
     */
    protected function buildCustomer($magentoCustomer)
    {
        // Email, first name and last name
        $customer = [
            'first_name' => ucwords(mb_strtolower(trim($magentoCustomer->firstname))),
            'last_name'  => ucwords(mb_strtolower(trim($magentoCustomer->lastname))),
            'tags'       => self::CUSTOMER_TAG,
        ];

        // Email
        if (isset($magentoCustomer->email) && !empty(trim($magentoCustomer->email))) {
            $customer['email'] = mb_strtolower(trim($magentoCustomer->email));
        }

        // Document
        if (isset($magentoCustomer->dni) && ($document = $this->validDocument($magentoCustomer->dni))) {
            $customer['document']      = $document;
            $customer['document_type'] = 'DNI';
        }

        // Address
        if (isset($magentoCustomer->addressInfo) && !empty($magentoCustomer->addressInfo)) {
            $address = $magentoCustomer->addressInfo;
            $customer += [
                // TO-DO convertir país de ISO2 a ISO3
                'country'   => isset($address->country_id) ? $address->country_id : null,
                'state'     => isset($address->region) ? ucwords(mb_strtolower(trim($address->region))) : null,
                'street'    => isset($address->street) ? ucwords(mb_strtolower(trim($address->street))) : null,
                'city'      => isset($address->city) ? ucwords(mb_strtolower(trim($address->city))) : null,
                'postcode'  => isset($address->postcode) ? $address->postcode : null,
                'telephone' => isset($address->telephone) ? $address->telephone : null,
            ];
        }

        // Birthdate
        if (isset($magentoCustomer->dob)) {
            $customer['birthdate'] = trim($magentoCustomer->dob);
        }

        // Gender
        if (isset($magentoCustomer->gender)) {
            $customer['gender'] = ($magentoCustomer->gender === "1") ? 'M' : (($magentoCustomer->gender === "2") ? 'F' : null);
        }

        // Group
        if (isset($magentoCustomer->group_id) && !empty($magentoCustomer->group_id)) {
            $customer['tags'] .= ",group" . $magentoCustomer->group_id;
        }

        // Custom Attributes
        foreach ($this->filters as $filter) {
            if (method_exists($filter, 'getCustomerCustomAttributes')) {
                $customer['custom_attributes'] = $filter->getCustomerCustomAttributes($magentoCustomer);
            }
        }

        // Clean empty values
        foreach ($customer as $key => $value) {
            if (empty($value)) {
                unset($customer[$key]);
            } elseif (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (empty($subValue)) {
                        unset($customer[$key][$subKey]);
                    }
                }
            }
        }

        // Check if customer has email or document
        if (empty($customer['email']) && (!isset($customer['document']) || empty($customer['document']))) {
            $this->logger->info("Customer " . $magentoCustomer->customer_id . " has no valid document or email");
            return null;
        }

        return $customer;
    }

    /**
     * Validates a document
     * @param  [type] $document [description]
     * @return [type]           [description]
     */
    protected function validDocument($document)
    {
        $document = trim(str_replace([".", "-", " ", "(", ")"], "", $document));
        return !empty($document) && preg_match("/^\d{7,}$/", $document) !== false ? $document : null;
    }

    /**
     * Maps Magento payment to WoowUp's API format
     * @param  [type] $paymentInfo [description]
     * @return [type]              [description]
     */
    protected function buildOrderPayment($paymentInfo)
    {
        $payment = [];

        if (isset($paymentInfo->payment_type_id) && !empty($paymentInfo->payment_type_id)) {
            $payment['type'] = $this->getPaymentType($paymentInfo->payment_type_id);
        }

        if (isset($paymentInfo->payment_method) && !empty($paymentInfo->payment_method)) {
            $payment['brand'] = ucwords(mb_strtolower(trim($paymentInfo->payment_method)));
        }

        if (isset($paymentInfo->cardTruncated) && !empty($paymentInfo->cardTruncated)) {
            $payment['first_digits'] = substr(str_replace(' ', '', $paymentInfo->cardTruncated), 0, 6);
        }

        if (isset($paymentInfo->installments) && !empty($paymentInfo->installments)) {
            $payment['installments'] = (int) trim($paymentInfo->installments);
        }

        if (isset($paymentInfo->total_amount) && !empty($paymentInfo->total_amount)) {
            $payment['amount'] = (float) $paymentInfo->total_amount;
        }

        return $payment;
    }

    /**
     * Maps Magento payment type to WoowUp's API format
     * @param  [type] $type [description]
     * @return [type]       [description]
     */
    protected function getPaymentType($type)
    {
        if (strpos($type, 'mercadopago') !== false) {
            return 'mercadopago';
        }

        if (strpos($type, 'todopago') !== false) {
            return 'todopago';
        }

        if (strpos($type, 'credit') !== false) {
            return 'credit';
        }

        if (strpos($type, 'debit') !== false) {
            return 'debit';
        }

        return 'other';
    }

    /**
     * Gets SOAP client according to defined version in property $config
     * @return [type] [description]
     */
    protected function getApiClient()
    {
        if (!isset($this->config['version'])) {
            throw new \Exception("Undefined magento api version");
        }

        switch ($this->config['version']) {
            case 1:
                return new SoapClientV1($this->config, $this->logger);
                break;
            case 2:
                return new SoapClientV2($this->config);
                break;
            default:
                throw new Exception("Unknown magento api version: " . $this->config['version']);
                break;
        }
    }

    /**
     * Sets the store id in property $config and in SOAP Client
     * @param [type] $storeId [description]
     */
    protected function setStore($storeId)
    {
        $this->config['store_id'] = $storeId;
        $this->client->setStore($storeId);
    }

    /**
     * Gets the current store
     * @return [type] [description]
     */
    protected function getStore()
    {
        return isset($this->config['store_id']) ? $this->config['store_id'] : null;
    }

    /**
     * Gets the instantiated SOAP Client
     * @return [type] [description]
     */
    protected function getClient()
    {
        return $this->client;
    }

    /**
     * Calls SOAP Client to list products between two dates
     * @param  [type] $from [description]
     * @param  [type] $to   [description]
     * @return [type]       [description]
     */
    protected function listProducts($from = null, $to = null)
    {
        return $this->client->listProducts($from, $to);
    }

    /**
     * Gets a product's info
     * @param  [type] $id    [description]
     * @param  string $field [description]
     * @return [type]        [description]
     */
    protected function findProductInfo($id, $field = 'sku')
    {
        return $this->client->findProductInfo($id, $field);
    }

    /**
     * Gets product_types defined in property $config
     * @return [type] [description]
     */
    protected function getProductTypes()
    {
        return isset($this->config['product_types']) ? explode(',', $this->config['product_types']) : [];
    }

    /**
     * Maps a Magento Product to WoowUp's API format
     * @param  [type] $id          [description]
     * @param  [type] $type        [description]
     * @param  [type] $productInfo [description]
     * @return [type]              [description]
     */
    protected function buildProduct($id, $type, $productInfo)
    {
        $product = [];
        if (empty($productInfo) || empty($productInfo->sku)) {
            $this->logger->info("Product not found for sku #{$id}");
            return null;
        }

        if (!isset($productInfo->type_id) || $productInfo->type_id != $type) {
            $this->logger->info("Product of type {$productInfo->type_id} does not match {$type}");
            return null;
        }

        if (!isset($productInfo->name) || empty($productInfo->name)) {
            return null;
        }

        $specifications = null;
        $stockQuantity  = 0;
        $basename       = null;
        $stockInfo      = null;

        $inStock     = $this->_isVisible($productInfo);
        $isAvailable = $this->_isAvailable($productInfo);

        if ($inStock && $isAvailable) {
            $stockInfo = $this->getStockForProduct($id);

            if (!empty($stockInfo)) {
                $stockQuantity = (int) $stockInfo[0]->qty;
                $inStock       = (boolean) $stockInfo[0]->is_in_stock;

                if ($inStock && $stockQuantity == 0) {
                    // pongo el stock en 1 porque realmente hay stock pero no le ponen cantidad en algunos casos
                    $stockQuantity = 1;
                }
            }
        }

        $description       = $this->_getDescription($productInfo);
        $imageUrl          = $this->_getImageUrl($id, $productInfo);
        $thumbnailUrl      = $this->_getThumbnailUrl($id, $productInfo);
        $price             = isset($productInfo->price) && !is_null($productInfo->price) ? (float) $productInfo->price : null;
        $offerPrice        = $this->_getOfferPrice($productInfo);
        $productCategories = null;

        $sku = $productInfo->sku;
        $parentSku = '';
        foreach ($this->filters as $filter) {
            if (method_exists($filter, 'filterSku')) {
                $sku = $filter->filterSku($sku);
            }
            if (method_exists($filter, 'getCustomAttributes')) {
                $product['custom_attributes'] = $filter->getCustomAttributes($productInfo);
            }
            if (method_exists($filter, 'getParentSku')) {
                $parentSku = $filter->getParentSku($sku);
            }
        }

        if ($parentSku && !empty($parentSku)) {
            $parentInfo = $this->findProductInfo($parentSku);
            $imageUrl = $this->_getImageUrl($parentSku, $productInfo);
            $thumbnailUrl = $this->_getThumbnailUrl($parentSku, $productInfo);
        }

        $product += [
            'sku'           => $sku,
            'name'          => $productInfo->name,
            'description'   => $description,
            'price'         => $price,
            'image_url'     => $imageUrl ? $imageUrl : '',
            'thumbnail_url' => $thumbnailUrl ? $thumbnailUrl : '',
            'stock'         => $stockQuantity,
            'available'     => ($inStock && $isAvailable),
        ];

        if ($offerPrice !== null) {
            $product['offer_price'] = (float) $offerPrice;
        }

        if ($this->config['categories'] && isset($productInfo->{$this->categoriesField}) && !empty($productInfo->{$this->categoriesField})) {
            $product['category'] = $this->buildProductCategory($productInfo->{$this->categoriesField});
        }

        if ($parentSku && !empty($parentSku)) {
            $product['url'] = $this->_getUrl($parentInfo, $productCategories);
        } else {
            $product['url'] = $this->_getUrl($productInfo, $productCategories);
        }

        return $product;
    }

    /**
     * Builds Woowup category for a product
     * @param  [type] $categoryIds [description]
     * @return [type]              [description]
     */
    protected function buildProductCategory($categoryIds)
    {
        $category = [];

        $categoryId = array_pop($categoryIds);
        if (!isset($this->categories[$categoryId])) {
            $this->logger->info("Category id $categoryId not found");
            return $category;
        }

        $category[] = $this->categories[$categoryId];
        $parentsIds = $this->categories[$categoryId]['path'];
        while (!empty($parentsIds)) {
            $parentId = array_pop($parentsIds);
            if (!isset($this->categories[$parentId])) {
                break;
            }
            array_unshift($category, $this->categories[$parentId]);
        }

        return $category;
    }

    /**
     * Flatterns the category tree to an array
     * @param  [type] $tree       [description]
     * @param  array  $parentPath [description]
     * @return [type]             [description]
     */
    protected function flatternCategoryTree($tree, $parentPath = [])
    {
        $categories = [];

        foreach ($tree as $leaf) {
            $categories[$leaf['id']] = [
                'id'        => $leaf['id'],
                'name'      => ucwords(mb_strtolower($leaf['name'])),
                'url'       => $leaf['url_path'] ? $leaf['url_path'] : '',
                'image_url' => $leaf['image'] ? $leaf['image'] : '',
                'path'      => $parentPath,
            ];

            $path   = $parentPath;
            $path[] = $leaf['id'];

            if (isset($leaf['children']) && !empty($leaf['children'])) {
                $categories += $this->flatternCategoryTree($leaf['children'], $path);
            }
        }

        return $categories;
    }

    /**
     * Returns if a magento product is marked as available
     * @param  [type]  $productInfo [description]
     * @return boolean              [description]
     */
    protected function _isAvailable($productInfo)
    {
        return isset($productInfo->status) && $productInfo->status == self::PRODUCT_STATUS_ENABLED;
    }

    /**
     * Returns if a Magento product is marked as visible
     * @param  [type]  $productInfo [description]
     * @return boolean              [description]
     */
    protected function _isVisible($productInfo)
    {
        return isset($productInfo->visibility) && in_array($productInfo->visibility, [self::PRODUCT_VISIBILITY_IN_SEARCH, self::PRODUCT_VISIBILITY_IN_CATALOG, self::PRODUCT_VISIBILITY_BOTH]);
    }

    /**
     * Returns the stock of a Magento product
     * @param  [type] $id [description]
     * @return [type]     [description]
     */
    protected function getStockForProduct($id)
    {
        return $this->client->getStockForProduct($id);
    }

    /**
     * Returns the description of a Magento product
     * @param  [type] $productInfo [description]
     * @return [type]              [description]
     */
    protected function _getDescription($productInfo)
    {
        $description = null;

        if (isset($productInfo->description)) {
            $description = $productInfo->description;
        } elseif (isset($productInfo->short_description)) {
            $description = $productInfo->short_description;
        }

        return $description;
    }

    /**
     * Returns the image url of a Magento product
     * @param  [type] $sku         [description]
     * @param  [type] $productInfo [description]
     * @return [type]              [description]
     */
    protected function _getImageUrl($sku, $productInfo)
    {
        $imageUrl  = null;
        $mediaInfo = $this->getMediaForProduct($sku);

        if (!empty($mediaInfo)) {
            $minPosition = null;

            foreach ($mediaInfo as $media) {
                if (isset($productInfo->image_label) && isset($media->label) && $productInfo->image_label && $media->label == $productInfo->image_label) {
                    $imageUrl = $media->url;
                }

                if (is_null($imageUrl) && (is_null($minPosition) || $minPosition->position > $media->position)) {
                    $minPosition = $media;
                }
            }

            if (is_null($imageUrl)) {
                $imageUrl = $minPosition->url;
            }
        }

        return $imageUrl;
    }

    /**
     * Returns the thumbnail url for a Magento product
     * @param  [type] $sku         [description]
     * @param  [type] $productInfo [description]
     * @return [type]              [description]
     */
    protected function _getThumbnailUrl($sku, $productInfo)
    {
        $imageUrl  = null;
        $mediaInfo = $this->getMediaForProduct($sku);

        if (!empty($mediaInfo)) {
            $minPosition = null;

            foreach ($mediaInfo as $media) {
                if (isset($productInfo->thumbnail_label) && isset($media->label) && $productInfo->thumbnail_label && $media->label == $productInfo->thumbnail_label) {
                    $imageUrl = $media->url;
                }

                if (is_null($imageUrl) && (is_null($minPosition) || $minPosition->position > $media->position)) {
                    $minPosition = $media;
                }
            }

            if (is_null($imageUrl)) {
                $imageUrl = $minPosition->url;
            }
        }

        return $imageUrl;
    }

    /**
     * Calls SOAP client to get media info for a Magento Product
     * @param  [type] $id [description]
     * @return [type]     [description]
     */
    protected function getMediaForProduct($id)
    {
        return $this->client->getMediaForProduct($id);
    }

    /**
     * Returns the store URL for a Magento product
     * @param  [type] $productInfo       [description]
     * @param  [type] $productCategories [description]
     * @return [type]                    [description]
     */
    protected function _getUrl($productInfo, $productCategories = null)
    {
        $url = isset($productInfo->{$this->urlField}) ? $productInfo->{$this->urlField} : null;

        $url = $this->config['host'] . '/' . $url;

        foreach ($this->filters as $filter) {
            if (method_exists($filter, 'filterUrl')) {
                $url = $filter->filterUrl($url);
            }
        }

        return $url;
    }

    /**
     * Builds and returns flatterned category tree
     * @return [type] [description]
     */
    protected function getCategories()
    {
        $this->logger->info("Getting categories");

        $tree       = $this->findCategories();
        $categories = $this->flatternCategoryTree($tree);

        $this->logger->info("Search for categories finished");

        return $categories;
    }

    /**
     * Calls SOAP client to get categories
     * @return [type] [description]
     */
    protected function findCategories()
    {
        $categories = $this->client->findCategories();

        return (isset($categories->children)) ? $this->buildCategoryTree($categories->children) : [];
    }

    /**
     * Builds the category tree
     * @param  [type] $categories [description]
     * @return [type]             [description]
     */
    protected function buildCategoryTree($categories)
    {
        $tree = [];

        foreach ($categories as $category) {
            $this->logger->info("Searching info for category {$category->name}");
            $info = $this->getCategoryInfo($category->category_id);

            $tree[] = [
                'id'       => $category->category_id,
                'name'     => $category->name,
                'children' =>
                isset($category->children) && !empty($category->children) ?
                $this->buildCategoryTree($category->children) : [],
                'path'     => !empty($info) && isset($info->path) && !empty($info->path) ? $info->path : "",
                'url_path' => !empty($info) && isset($info->url_path) && !empty($info->url_path) && isset($this->config['host']) ? ($this->config['host'] . "/" . $info->url_path) : null,
                'url_key'  => !empty($info) && isset($info->url_key) && !empty($info->url_key) ? $info->url_key : null,
                'image'    => null,
            ];
        }

        return $tree;
    }

    /**
     * Calls SOAP client to return category info
     * @param  [type] $categoryId [description]
     * @return [type]             [description]
     */
    protected function getCategoryInfo($categoryId)
    {
        return $this->client->findCategory($categoryId);
    }

    protected function validateConfig($config)
    {
        $this->logger->info("Validating configuration");

        if (!isset($config['host']) || empty($config['host'])) {
            throw new \Exception("Field 'host' must be specified", 1);
        }

        if (!isset($config['apiuser']) || empty($config['apiuser'])) {
            throw new \Exception("Field 'apiuser' must be specified", 1);
        }

        if (!isset($config['apikey']) || empty($config['apikey'])) {
            throw new \Exception("Field 'apikey' must be specified", 1);
        }

        if (!isset($config['version']) || empty($config['version'])) {
            throw new \Exception("Field 'version' must be specified", 1);
        }

        if (($config['version'] !== 1) && ($config['version'] !== 2)) {
            throw new \Exception("Specified 'version' can be only 1 or 2", 1);
        }

        if (!isset($config['categories']) || empty($config['categories'])) {
            $config['categories'] = false;
            $this->logger->info("Categories sync disabled");
        }

        if (!isset($config['status']) || empty($config['status'])) {
            $this->logger->info("No 'status' specified, default: " . self::STATUS_COMPLETE);
            $config['status'] = array(self::STATUS_COMPLETE);
        }

        if (!isset($config['branchName']) || empty($config['branchName'])) {
            $this->logger->info("No 'branchName' specified, default: " . self::DEFAULT_BRANCH_NAME);
            $config['branchName'] = self::DEFAULT_BRANCH_NAME;
        }

        if (!isset($config['variations'])) {
            $this->logger->info("No 'variations' specified");
            $config['variations'] = [];
        }

        if (!isset($config['store_id'])) {
            $this->logger->info("No 'store_id' specified");
            $config['store_id'] = null;
        }

        return $config;
    }

    protected function _getOfferPrice($productInfo)
    {
        if (isset($productInfo->special_price) && !is_null($productInfo->special_price)) {
            return $productInfo->special_price;
        }

        return null;
    }

    public function importCarts($createdFrom, $createdTo)
    {
        //
    }

    /*
    protected function findProductAditionalAttributes($setId, $type = 'simple')
    {
    return $this->client->findProductAditionalAttributes($setId, $type);
    }

    public function getProductTypes()
    {
    return isset($this->config['product_types']) ? $this->config['product_types'] : [];
    }

    public function getContestId()
    {
    return isset($this->config['contest_id']) ? $this->config['contest_id'] : null;
    }

     */

    /*public function getOrder($id)
    {
    return $this->buildOrderInfo($this->client->findOrderInfo($id));
    }

    public function getOrdersWithoutBuild($fromDate, $toDate = null, $allStatus = false)
    {
    $i = 0;
    $groupByStatus = [];
    $date = date('Y-m-d', strtotime($fromDate));
    $toDate = is_null($toDate) ? date('Y-m-d') : date('Y-m-d', strtotime($toDate));

    echo "Buscando desde {$date} al {$toDate}\n";

    while ($date <= $toDate) {
    $orders = $this->client->findOrders($date . " 00:00:00", $date . " 23:59:59");

    echo "Cantidad de ventas en el día {$date}: " . count($orders) . "\n";

    foreach ($orders as $order) {
    if (($allStatus || in_array($order->status, $this->config['status'])) && (empty($this->config['store_id']) || $order->store_id == $this->config['store_id'])) {
    yield $order;
    }
    }

    $date = date('Y-m-d', strtotime($date . " +1 day"));
    }
    }*/

    /*

public function getNewCustomers($from, $to = null)
{
return $this->getCustomers($from, $to, true);
}

public function findProductInfo($id, $field = 'sku')
{
return $this->client->findProductInfo($id, $field);
}

public function findProductAttributes($id)
{
return $this->client->findProductAttributes($id);
}

public function findProductAttributeSets()
{
return $this->client->findProductAttributeSets();
}

public function findProductCustomOptions($id)
{
return $this->client->findProductCustomOptions($id);
}*/
}
