<?php

namespace GraphQL\Doctrine\Helper;

use MyCLabs\Enum\Enum;

class ActionMethod extends Enum {

    const CREATE = 'create';
    const UPDATE = 'update';
    const DELETE = 'delete';
    const GET = 'get';
}