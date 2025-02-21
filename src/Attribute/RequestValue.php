<?php
declare(strict_types=1);
namespace Hengeb\Router\Attribute;

use Attribute;

/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestValue {
    public function __construct(
        public string $name = '',
        public string $identifier = '',
    )
    {
    }
}
