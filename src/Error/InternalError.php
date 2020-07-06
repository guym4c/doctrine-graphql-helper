<?php

namespace GraphQL\Doctrine\Helper\Error;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\Error;

class InternalError extends Exception implements ClientAware {

    public function isClientSafe() {
        return false;
    }

    public function getCategory() {
        return Error::CATEGORY_INTERNAL;
    }
}