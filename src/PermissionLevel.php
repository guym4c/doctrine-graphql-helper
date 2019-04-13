<?php

namespace Guym4c\GraphQL\Doctrine\Helper;

use MyCLabs\Enum\Enum;

class PermissionLevel extends Enum {

    const NONE = 'none';
    const PERMISSIVE = 'permissive';
    const ALL = 'all';
}