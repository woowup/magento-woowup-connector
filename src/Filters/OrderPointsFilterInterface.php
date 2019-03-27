<?php

namespace MagentoWoowUpConnector\Filters;

interface OrderPointsFilterInterface
{
    public function getPurchasePoints($order);
}
