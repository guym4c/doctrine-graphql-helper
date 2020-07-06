<?php

namespace GraphQL\Doctrine\Helper\Error;

use GraphQL\Doctrine\Helper\ActionMethod;
use GraphQL\Error\UserError;

class PermissionsError extends UserError {

    public function __construct(string $entityName, string $method = ActionMethod::GET) {
        parent::__construct('Permission to perform action %s on entity %s denied', $method, $entityName);
    }
}