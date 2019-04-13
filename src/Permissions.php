<?php

namespace GraphQL\Doctrine\Helper;

abstract class Permissions {

    /**
     * @var array
     *
     * Extend this class and define the permissions in the subclass.
     */
    protected static $scopes;

    public static function scopeExists(string $id): bool {
        $result = $id == '*' ||
            array_key_exists($id, static::$scopes);
        return $result;
    }

    public static function getPermission(string $id, string $entity, string $method): string {

        if (!self::scopeExists($id)) {
            return PermissionLevel::NONE;
        }

        $scopes = static::$scopes[$id];

        if (!empty($scopes[0]) &&
            $scopes[0] == '*') {
            return PermissionLevel::ALL;
        }

        if (empty($scopes[$entity])) {
            return PermissionLevel::NONE;
        }

        if (in_array($method, $scopes[$entity])) {
            return $scopes[$entity][$method];
        }

        return PermissionLevel::NONE;
    }
}