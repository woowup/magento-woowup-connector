<?php

namespace MagentoWoowUpConnector\Interfaces;

interface ClientInterface
{
    public function getCustomerInfo($customerId);

    public function findCategories();

    public function findOrderInfo($id);

    public function findProductInfo($id);

    public function findProductAttributeSets();

    public function findProductAttributes($id);

    public function findOrders($from, $to);

    public function findCustomers($from, $to);
}
