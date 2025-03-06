<?php

namespace Hengeb\Router\Interface;

interface RetrievableModel {
    public static function retrieveModel(mixed $id, string $identifierName = 'id'): ?static;
}
