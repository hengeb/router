<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router\Interface;

interface RetrievableModel {
    public static function retrieveModel(mixed $id, string $identifierName = 'id'): ?static;
}
