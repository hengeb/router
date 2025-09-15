<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router\Attribute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_METHOD)]
class Route {
    /**
     * @param string $matcher "METHOD /path?query", e.g. "GET /search?q={query}" where path and query may contain named placeholders
     *      the name of each placeholder has to match the name of an argument of the controller method
     *      you can include a bit of regex in the placeholder like: {[0-9]+:id} to allow only numbers in the "id" argument
     *      you can use a placeholder to retrieve a model like: {id=>user} to fetch a user by its id attribute
     */
    public function __construct(public string $matcher) {}
}
