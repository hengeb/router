<?php
declare(strict_types=1);
namespace Hengeb\Router\Attribute;

use Attribute;

/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class Route {
    /**
     * @param string $matcher "METHOD /path?query", e.g. "GET /search?q={query}" where path and query may contain named placeholders
     *      the name of each placeholder has to match the name of an argument of the controller method
     *      you can include a bit of regex in the placeholder like: {[0-9]+:id} to allow only numbers in the "id" argument
     *      you can use a placeholder to retrieve a model like: {id=>user} to fetch a user by its id attribute
     * @param array|bool $allow
     *      true: allow public access
     *      false: deny access (inactive route)
     *      array:  [$property => $valueTemplate, ...] allow access if one of the conditions is fullfilled
     *      Router will determine the $value of $valueTemplate and then check if $currentUser->{'has' . $property}($value) === true
     *      ($value will be casted to int if neccessary)
     *      i.e. a method of this name has to exist in the $currentUser object
     *      $valueTemplate might be something like:
     *          literal value like 'team', e.g. ['group' => 'team']: will check if current user has the group 'team'
     *          list of literals like 'admin|superadmin', e.g. ['role' => 'admin|superadmin'] will check if the user has one of the roles
     *          list of literals like 'admin&superadmin', e.g. ['role' => 'admin&superadmin'] will check if the user has all of the roles
     *          a simple function call to an argument of the function like '$group->getName()' or '$user->getId()', e.g.
     *              ['group' => '$group->getName()', 'id' => '$user->get("id")] will check if either
     *              $currentUser->hasGroup($group->getName()) OR $currentUser->hasId($user->get("id")) is true
     *              where $group and $user have to be parameters of the routing method
     * @param bool $checkCsrfToken null => auto by HTTP method (GET: false, otherwise: true)
     */
    public function __construct(public string $matcher, public array|bool $allow, public ?bool $checkCsrfToken = null)
    {
    }
}
