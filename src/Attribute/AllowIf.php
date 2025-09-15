<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router\Attribute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_METHOD)]
class AllowIf implements AccessAttribute {
    public array $allow = [];

    /**
     * if more than one AllowIf is present, access is granted if the conditions of ANY of the attributes are fulfilled
     * @param array $allow [$property => $valueTemplate, ...] allow access if ALL of the conditions in the array are fullfilled
     *     Router will determine the $value of $valueTemplate and then check if $currentUser->{'has' . $property}($value) === true
     *     ($value will be casted to int if neccessary)
     *     i.e. a method of this name has to exist in the $currentUser object
     *     $valueTemplate might be something like:
     *         literal value like 'team', e.g. ['group' => 'team']: will check if current user has the group 'team'
     *         a simple function call to an argument of the function like '$group->getName()' or '$user->getId()', e.g.
     *             ['group' => '$group->getName()', 'id' => '$user->get("id")] will check if either
     *             $currentUser->hasGroup($group->getName()) OR $currentUser->hasId($user->get("id")) is true
     *             where $group and $user have to be parameters of the routing method
     */
    public function __construct(string ...$allow)
    {
        $this->allow = $allow;
    }
}
