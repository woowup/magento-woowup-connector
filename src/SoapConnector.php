<?php

namespace MagentoWoowUpConnector;

use Psr;
use MagentoWoowUpConnector\Clients\SoapClientV1;
use MagentoWoowUpConnector\Clients\SoapClientV2;
use MagentoWoowUpConnector\WoowUpHelper;

class SoapConnector
{
    const CONNECTION_TIMEOUT = 300; //5min

    const STATUS_COMPLETE = 'complete';

    const PRODUCT_VISIBILITY_NOT_VISIBLE    = 1;
    const PRODUCT_VISIBILITY_IN_CATALOG     = 2;
    const PRODUCT_VISIBILITY_IN_SEARCH      = 3;
    const PRODUCT_VISIBILITY_BOTH           = 4;

    const PRODUCT_STATUS_DISABLED = 2;
    const PRODUCT_STATUS_ENABLED = 1;

    const PRODUCT_TYPE_SIMPLE = 'simple';
    const PRODUCT_TYPE_CONFIGURABLE = 'configurable';

    const SERVICEUID_FIELD = 'order.customer_email';

    const CUSTOMER_TAG = 'Magento';

    const DEFAULT_BRANCH_NAME = 'MAGENTO';

    const DEFAULT_ORDERS_DOWNLOAD_DAYS = 5;

    protected $sessionId;
    protected $variations;
    protected $productsInfo;
    protected $customersInfo;
    protected $connectionTime;
    protected $branchName;
    protected $client;
    protected $logger;
    protected $woowup;

    function __construct(array $config, $logger, $woowupClient)
    {
        $this->sessionId = null;
        $this->connectionTime = null;
        $this->config = $config;
        $this->variations = explode(',', $config['variations']);
        $this->productsInfo = [];
        $this->customersInfo = [];
        $this->branchName = (isset($config['branchName']) && !empty($config['branchName'])) ? $config['branchName'] : self::DEFAULT_BRANCH_NAME;
        $this->client = $this->getApiClient();
        $this->logger = $logger;
        $this->woowup = new WoowUpHelper($woowupClient, $logger);
    }

    public function importCustomers($days = 5)
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
                $this->upsertCustomer($customer);
            }
        }

        $stats = $this->woowup->getApiStats();

        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $stats['customers']['created']);
        $this->logger->info("Updated customers: " . $stats['customers']['updated']);
        $this->logger->info("Failed customers: " . count($stats['customers']['failed']));
    }

    public function importOrders($days = null, $update = false)
    {
        if (!$days) {
            $days = self::DEFAULT_ORDERS_DOWNLOAD_DAYS;
        }
        $this->logger->info("Importing customers from $days days");
        $fromDate = date('Y-m-d', strtotime("-$days days"));

        if (!empty($this->config['stores'])) {
            $stores = $this->config['stores'];
        } else {
            $stores = [null];
        }

        foreach ($stores as $store) {
            $this->setStore($store);

            foreach ($this->getOrders($fromDate) as $order) {
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
            $i = 0;
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

    public function getOrders($fromDate, $toDate = null, $importing = false)
    {
        $i = 0;
        $groupByStatus = [];
        $date = date('Y-m-d', strtotime($fromDate));
        $toDate = is_null($toDate) ? date('Y-m-d') : date('Y-m-d', strtotime($toDate));

        $this->logger->info("Buscando desde {$date} al {$toDate}");
        $message = "Buscando ventas actualizadas desde {$date} hasta {$toDate}.\n";
        $message .= "Estados para descargar: " . json_encode($this->config['status']) . "\n";

        if (isset($this->config['store_id']) && !empty($this->config['store_id'])) {
            $message .= "Tienda: " . $this->config['store_id'];
        }

        $this->logger->info($message);

        while ($date <= $toDate) {
            $magentoOrders = $this->client->findOrders($date . " 00:00:00", $date . " 23:59:59");

            $this->logger->info("Cantidad de ventas en el día {$date}: " . count($magentoOrders));

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

        $this->logger->info("Estado de ventas para la cuenta ".$this->config['app_id'].": " . json_encode($this->config['status']));
        $this->logger->info("Ventas descargadas por estado: ".json_encode($groupByStatus));
    }

    protected function getCustomerInfo($customerId)
    {
        $this->logger->info("Getting info for customer $customerId");
        return $this->client->getCustomerInfo($customerId);
    }

    protected function buildOrder($magentoOrder, $importing = false)
    {
        $this->logger->info("Building order " . $magentoOrder->increment_id);
        $order = [];

        $customer = null;
        $magentoOrderInfo = $this->client->findOrderInfo($magentoOrder->increment_id);

        if (isset($magentoOrder->customer_id)) {
            $magentoCustomer = $this->client->getCustomerInfo($magentoOrder->customer_id);
            $customer = $this->buildCustomer($magentoCustomer);
        }

        // Find document in payment info
        if (!isset($customer['document']) && isset($magentoOrderInfo->payment) && !empty($magentoOrderInfo->payment)) {
            $payment = $magentoOrderInfo->payment;
            if (isset($payment->additional_information) && isset($payment->additional_information->docNumber) && !empty($payment->additional_information->docNumber)) {
                $customer['document'] = trim($payment->additional_information->docNumber);
                $customer['document_type'] = trim($payment->additional_information->docType);
            }
        }

        if (($customer === null) || (!isset($customer['email']) && !isset($customer['document']))) {
            $this->logger->info("Invalid customer");
            return null;
        }

        $order['invoice_number'] = $magentoOrder->increment_id;
        $order['customer'] = $customer;
        if (isset($customer['document'])) {
            $order['document'] = $customer['document'];
        } else {
            $order['email'] = $customer['email'];
        }

        $order['purchase_detail'] = [];
        foreach ($magentoOrderInfo->items as $item) {
            if (!isset($item->sku)) {
                $this->logger->info("Invalid SKU");
                continue;
            }

            $magentoProduct = $this->client->findProductInfo($item->sku);

            $categoryId = null;
            $categoryPath = null;
            $categories = [];
            $productUrl = null;
            $productThumbnail = null;
            $productPicture = null;

            $product = [
                'sku' => trim($item->sku),
                'product_name' => isset($item->name) ? ucwords(mb_strtolower(trim($item->name))) : '',
                'quantity' => (int) $item->qty_ordered,
                'unit_price' => (float) $item->price,
                'variations' => [],
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
                        'name' => ucwords(mb_strtolower($variation)),
                        'value' => $magentoProduct->{$variation},
                    ];
                }
            }

            // TO-DO agregar proceso de categorias
            /*if (!is_null($product) && $this->config['categories'] && isset($product->category_ids) && count($product->category_ids) > 0) {
                $categories = $product->category_ids;
                $categoryId = $product->category_ids[0];
                $categoryPath = ProductCategoryService::buildCategoryPath(
                    $this->config['contest_id'],
                    $categoryId
                );
            }*/

            $order['purchase_detail'][] = $product;
        }
        
        // createtime y approvedtime
        $order['createtime'] = $magentoOrder->created_at;
        $order['approvedtime'] = date('c');

        // tienda
        $order['branch_name'] = $this->branchName;

        // precios
        $order['prices'] = [
            'gross' => (float) $magentoOrder->base_subtotal,
            'discount' => (float) abs($magentoOrder->base_discount_amount),
            'tax' => (float) $magentoOrder->base_tax_amount,
            'shipping' => (float) $magentoOrder->base_shipping_amount,
            'total' => (float) $magentoOrder->base_subtotal - abs($magentoOrder->base_discount_amount),
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

        return $order;
    }

    protected function getStores()
    {
        return $this->client->getStores();
    }

    protected function buildCustomer($magentoCustomer)
    {
        // Email, first name and last name
        $customer = [
            'email' => mb_strtolower(trim($magentoCustomer->email)),
            'first_name' => ucwords(mb_strtolower(trim($magentoCustomer->firstname))),
            'last_name' => ucwords(mb_strtolower(trim($magentoCustomer->lastname))),
            'tags' => self::CUSTOMER_TAG,
        ];

        // Document
        if (isset($magentoCustomer->dni) && ($document = $this->validDocument($magentoCustomer->dni))) {
            $customer['document'] = $document;
            $customer['document_type'] = 'DNI';
        }

        // Address
        if (isset($magentoCustomer->addressInfo) && !empty($magentoCustomer->addressInfo)) {
            $address = $magentoCustomer->addressInfo;
            $customer += [
                // TO-DO convertir país de ISO2 a ISO3
                'country' => isset($address->country_id) ? $address->country_id : null,
                'state' => isset($address->region) ? ucwords(mb_strtolower(trim($address->region))) : null,
                'street' => isset($address->street) ? ucwords(mb_strtolower(trim($address->street))) : null,
                'city' => isset($address->city) ? ucwords(mb_strtolower(trim($address->city))) : null,
                'postcode' => isset($address->postcode) ? $address->postcode : null,
                'telephone' => isset($address->telephone) ? $address->telephone : null,
            ];
        }

        // Birthdate
        if (isset($magentoCustomer->dob)) {
            $customer['birthdate'] = trim($customer->dob);
        }

        // Gender
        if (isset($magentoCustomer->gender)) {
            $customer['gender'] = ($customer->gender === "1") ? 'M' : (($customer->gender === "2") ? 'F' : null);
        }

        // Group
        if (isset($magentoCustomer->group_id) && !empty($magentoCustomer->group_id)) {
            $customer['tags'] .= ",group" . $magentoCustomer->group_id;
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

    protected function validDocument($document)
    {
        $document = trim(str_replace([".", "-", " ", "(", ")"], "", $document));
        return !empty($document) && preg_match("/^\d{7,}$/", $document) !== false ? $document : null;
    }

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

    protected function getApiClient()
    {
        if (!isset($this->config['version'])) {
            throw new \Exception("Undefined magento api version");
        }

        switch ($this->config['version']) {
            case 1:
                return new SoapClientV1($this->config);
                break;
            case 2:
                return new SoapClientV2($this->config);
                break;
            default:
                throw new Exception("Unknown magento api version: ".$this->config['version']);
                break;
        }
    }

    protected function setStore($storeId)
    {
        $this->config['store_id'] = $storeId;
        $this->client->setStore($storeId);
    }

    protected function getStore()
    {
        return isset($this->config['store_id']) ? $this->config['store_id'] : null;
    }

    protected function getClient()
    {
        return $this->client;
    }

    /*    public function importProductById($id, $type, $productInfo)
    {
        if (empty($productInfo) || empty($productInfo->sku)) {
            echo "Producto no encontrado sku #{$id}\n";
            return null;
        }

        if (!isset($productInfo->type_id) || $productInfo->type_id != $type) {
            echo "Producto de tipo {$productInfo->type_id} vs {$type}\n";
            return null;
        }

        if (!isset($productInfo->name) || empty($productInfo->name)) {
            return null;
        }

        $specifications = null;
        $stockQuantity = null;
        $basename = null;
        $stockInfo = null;

        $inStock = $this->_isVisible($productInfo);
        $isAvailable = $this->_isAvailable($productInfo);

        if ($inStock && $isAvailable) {
            $stockInfo = $this->getStockForProduct($id);

            if (!empty($stockInfo)) {
                $stockQuantity = (int) $stockInfo[0]->qty;
                $inStock = (boolean) $stockInfo[0]->is_in_stock;

                if ($inStock && $stockQuantity == 0) {
                    // pongo el stock en 1 porque realmente hay stock pero no le ponen cantidad en algunos casos
                    $stockQuantity = 1;
                }
            }
        }

        $description = $this->_getDescription($productInfo);
        $imageUrl = $this->_getImageUrl($id, $productInfo);
        $thumbnailUrl = $this->_getThumbnailUrl($id, $productInfo);
        $price = isset($productInfo->price) && !is_null($productInfo->price) ? (float) $productInfo->price : null;
        $productCategories = null;

        $product = new \ProductConnectorModel($id);
        $product
        ->setContestId($this->config['contest_id'])
        ->setName($productInfo->name)
        ->setDescription($description)
        ->setPrice($price)
        ->setImageUrl($imageUrl)
        ->setThumbnailUrl($thumbnailUrl)
        ->setStock($stockQuantity)
        ->setAvailability($inStock && $isAvailable);

        if ($this->config['categories']) {
            list($productCategories, $categoryId, $categoryPath) = $this->_getCategories($this->config['contest_id'], $productInfo);
            $product
            ->setCategoryCode($categoryId)
            ->setCategoryPath($categoryPath);
        }

        $product->setUrl($this->_getUrl($productInfo, $productCategories));

        return $product;
    }*/

    /*    protected function _getDescription($productInfo)
    {
        $description = null;

        if (isset($productInfo->description)) {
            $description = $productInfo->description;
        } elseif (isset($productInfo->short_description)) {
            $description = $productInfo->short_description;
        }

        return $description;
    }

    public function _getImageUrl($sku, $productInfo)
    {
        $imageUrl = null;
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

    protected function _getThumbnailUrl($sku, $productInfo)
    {
        $imageUrl = null;
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

    protected function _getCategories($appId, $productInfo)
    {
        $categoryId = null;
        $categoryPath = null;
        $productCategories = null;

        if (isset($productInfo->category_ids) && !empty($productInfo->category_ids)) {
            $categoryId = $productInfo->category_ids[count($productInfo->category_ids) - 1];
            $categoryPath = ProductCategoryService::buildCategoryPath($appId, $categoryId);
            $productCategories = ProductCategoryService::buildCategoryTreeFromCode($appId, $categoryId);
        }

        return [$productCategories, $categoryId, $categoryPath];
    }

    protected function _getUrl($productInfo, $productCategories = null)
    {
        $url = isset($productInfo->url_path) ? $productInfo->url_path : null;

        return $url;
    }

    protected function _isAvailable($productInfo)
    {
        return isset($productInfo->status) && $productInfo->status == self::PRODUCT_STATUS_ENABLED;
    }

    protected function _isVisible($productInfo)
    {
        return isset($productInfo->visibility) && in_array($productInfo->visibility, [self::PRODUCT_VISIBILITY_IN_SEARCH, self::PRODUCT_VISIBILITY_IN_CATALOG, self::PRODUCT_VISIBILITY_BOTH]);
    }*/

    /*public function buildOrderInfo($orderInfo)
    {
        $customer = null;

        if (isset($orderInfo->customer_id)) {
            $customer = $this->client->getCustomerInfo($orderInfo->customer_id);
        }

        $serviceUid = $this->getServiceUidField($customer, $orderInfo);

        $o = new OrderConectorModel();
        $c = new CustomerConectorModel();

        foreach ($orderInfo->items as $item) {
            $product = $this->client->findProductInfo($item->sku);

            $categoryId = null;
            $categoryPath = null;
            $categories = [];
            $productUrl = null;
            $productThumbnail = null;
            $productPicture = null;

            if (!is_null($product) && isset($product->category_ids) && count($product->category_ids) > 0) {
                $categories = $product->category_ids;
                $categoryId = $product->category_ids[0];
                $categoryPath = ProductCategoryService::buildCategoryPath(
                    $this->config['contest_id'],
                    $categoryId
                );
            }

            $o->addInvoiceLine(
                $item->sku,
                $item->name,
                (int)$item->qty_ordered,
                $item->price,
                $categoryId,
                $categoryPath,
                null, //$variations,
                $productUrl,
                $productThumbnail,
                $productPicture,
                $categories
            );
        }


        $email = $orderInfo->customer_email;
        $document = $customer && isset($customer->dni) && !empty(trim($customer->dni)) ? trim($customer->dni) : null;

        if ($serviceUid == $email || $serviceUid == $document) {
            $serviceUid = null;
        }

        $c->setUid($serviceUid);
        $c->setEmail($orderInfo->customer_email);
        $c->setDocument($document);
        $c->setName($orderInfo->customer_firstname);
        $c->setLastName($orderInfo->customer_lastname);
        $c->setState($customer && isset($customer->region) ? $customer->region : null);
        $c->setStreet($customer && isset($customer->street) ? $customer->street : null);
        $c->setCountry($customer && isset($customer->country_id) ? $customer->country_id : null);

        if (!is_null($customer)) {
            $c->setTags(["group" . $customer->group_id]);
        }

        if (isset($orderInfo->customer_gender)) {
            if ($orderInfo->customer_gender == "1") {
                $c->setGender(UserService::MALE);
            } elseif ($orderInfo->customer_gender == "2") {
                $c->setGender(UserService::FEMALE);
            }
        }

        //echo $order->created_at . "\n";
        return $o
        ->setNumber($orderInfo->increment_id)
        ->setTotal($orderInfo->base_subtotal - abs($orderInfo->base_discount_amount))
        ->setGrossTotal($orderInfo->base_subtotal)
        ->setShippingTotal($orderInfo->base_shipping_amount)
        ->setTaxTotal($orderInfo->base_tax_amount)
        ->setDiscountTotal(abs($orderInfo->base_discount_amount))
        ->setDate($orderInfo->created_at)
        ->setCustomer($c);
    }*/

    /*public function getCategories()
    {
        $categories = $this->client->findCategories();

        return (isset($categories->children)) ? $this->buildCategoryTree($categories->children) : [];
    }

    protected function getCategoryInfo($categoryId)
    {
        return $this->client->findCategory($categoryId);
    }*/

        /*protected function findProductAditionalAttributes($setId, $type = 'simple')
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

    protected function buildCategoryTree($categories)
    {
        $tree = [];

        foreach ($categories as $category) {
            echo "Buscando informacion de categoria {$category->name}\n";
            $info = $this->getCategoryInfo($category->category_id);

            $tree[] = new CategoryConectorModel(
                $category->category_id,
                $category->name,
                isset($category->children) && !empty($category->children) ?
                $this->buildCategoryTree($category->children) : [],
                !empty($info) && isset($info->path) && !empty($info->path) ? $info->path : "",
                !empty($info) && isset($info->url_path) && !empty($info->url_path) && isset($this->config['host']) ? ($this->config['host']."/".$info->url_path) : null,
                null, #imagen
                !empty($info) && isset($info->url_key) && !empty($info->url_key) ? $info->url_key : null
            );
        }

        return $tree;
    }*/

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

    /*public function listProducts($from = null, $to = null)
    {
        return $this->client->listProducts($from, $to);
    }

    public function getNewCustomers($from, $to = null)
    {
        return $this->getCustomers($from, $to, true);
    }

    public function findProductInfo($id, $field = 'sku')
    {
        return $this->client->findProductInfo($id, $field);
    }

    public function getStockForProduct($id)
    {
        return $this->client->getStockForProduct($id);
    }

    public function getMediaForProduct($id)
    {
        return $this->client->getMediaForProduct($id);
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
