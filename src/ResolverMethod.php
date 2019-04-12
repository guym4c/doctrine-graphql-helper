<?php

namespace GraphQL\Doctrine\Helper;

use MyCLabs\Enum\Enum;

class ResolverMethod extends Enum {

    const CREATE = 'create';
    const UPDATE = 'update';
    const DELETE = 'delete';
    const GET = 'get';
}