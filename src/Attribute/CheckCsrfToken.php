<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class CheckCsrfToken
{
    public function __construct(public bool $check) {}
}
