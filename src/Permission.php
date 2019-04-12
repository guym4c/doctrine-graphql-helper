<?php

namespace GraphQL\Doctrine\Helper;

use MyCLabs\Enum\Enum;

abstract class Permission extends Enum {

    const NONE = 'none';
    const PERMISSIVE = 'permissive';
    const ALL = 'all';

    /**
     * @var array
     *
     * Extend this class and define the permissions in the subclass.
     */
    private static $scopes;

    private static function scopeExists(string $id): bool {
        $result = $id == '*' ||
            array_key_exists($id, static::$scopes);
        return $result;
    }

    public static function getPermission(string $id, string $entity, string $method): string {

        if (!self::scopeExists($id)) {
            return 'none';
        }

        $scopes = static::$scopes[$id];

        if (!empty($scopes[0]) &&
            $scopes[0] == '*') {
            return 'all';
        }

        if (empty($scopes[$entity])) {
            return 'none';
        }

        if (in_array($method, $scopes[$entity])) {
            return $scopes[$entity][$method];
        }

        return 'none';
    }
}