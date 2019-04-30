<?php

namespace GraphQL\Doctrine\Helper;

class Permissions {

    /** @var array */
    private $scopes;

    /**
     * Permissions constructor.
     * @param array $scopes
     */
    public function __construct(array $scopes) {
        $this->scopes = $scopes;
    }

    public function scopeExists(string $id): bool {
        $result = $id == '*' ||
            array_key_exists($id, $this->scopes);
        return $result;
    }

    public  function getPermission(string $id, string $entity, string $method): string {

        if (!$this->scopeExists($id)) {
            return PermissionLevel::NONE;
        }

        $scopes = $this->scopes[$id];

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