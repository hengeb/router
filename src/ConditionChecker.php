<?php
declare(strict_types=1);
namespace Hengeb\Router;

use Hengeb\Router\Attribute\RequireLogin;
use Hengeb\Router\Exception\AccessDeniedException;
use Hengeb\Router\Exception\NotLoggedInException;
use Hengeb\Router\Interface\CurrentUserInterface;

/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

/**
 * condition checker
 */
class ConditionChecker {
    public function __construct(
        private CurrentUserInterface $currentUser,
        private array $conditions
    )
    {
    }

    /**
     * evaluate the value of a value template of a rule's 'allow' condition
     * @return value
     */
    private function evaluateValueTemplate(string $template, array $args): mixed
    {
        if (!$template || $template[0] !== '$') {
            return $template;
        }

        $tokens = token_get_all('<?php ' . $template);
        $argName = substr($tokens[1][1], 1);
        $arg = $args[$argName] ?? throw new \LogicException("missing argument: $argName");

        switch (count($tokens)) {
            case 2: // $template = '$argName'
                $value = $arg;
                break;
            case 4: // $template = '$argName->$varName^'
                $varName = $tokens[3][1];
                $value = $arg->$varName;
                break;
            default: // $template = '$argName->$methodName(... $callArgs)';
                $methodName = $tokens[3][1];
                $argTokens = array_filter(
                    array_slice($tokens, 5), // arguments of the function start at this index
                    // omit whitespace and commas and the final bracket
                    fn($token) => in_array($token, ['!', '-'], true) || is_array($token) && $token[0] !== T_WHITESPACE
                );
                $callArgs = [];
                $not = false; // to parse booleans
                $factor = 1;
                foreach ($argTokens as $token) {
                    if ($token === '!') {
                        $not = true;
                        continue;
                    } else {
                        $not = false;
                    }
                    if ($token === '-') {
                        $factor = -1;
                        continue;
                    } else {
                        $factor = 1;
                    }
                    $callArgs[] = match($token[0]) {
                        T_CONSTANT_ENCAPSED_STRING => substr($token[1], 1, -1),
                        T_VARIABLE => match (gettype($arg = $args[substr($token[1], 1)] ?? throw new \LogicException("missing argument: {$token[1]}"))) {
                            'bool' => $not ^ $arg,
                            'int', 'float' => $factor * $arg,
                            default => $arg,
                        },
                        T_LNUMBER => $factor * intval($token[1]),
                        T_DNUMBER => $factor * floatval($token[1]),
                        T_STRING => match ($token[1]) {
                            'null' => null,
                            'true' => $not ^ true,
                            'false' => $not ^ false,
                            default => throw new \InvalidArgumentException("not supported: " . $token[1]),
                        },
                        default => throw new \InvalidArgumentException("not supported: " . $token[1]),
                    };
                }
                $value = $arg->$methodName(...$callArgs);
        }

        return $value;
    }

    /**
     * builds a closure that checks the value of a property of the current user
     */
    private function getValueChecker(string $propertyName): callable
    {
        foreach (["has$propertyName", "is$propertyName", $propertyName] as $methodName) {
            if (!method_exists($this->currentUser, $methodName)) {
                continue;
            }
            $method = new \ReflectionMethod($this->currentUser, $methodName);
            $parameters = $method->getParameters();
            if (count($parameters)) {
                $type = (string)$parameters[0]->getType();
                return function ($value) use ($method, $type) {
                    $value = match($type) {
                        'int' => intval($value),
                        'bool' => boolval($value),
                        default => $value,
                    };
                    return $method->invoke($this->currentUser, $value);
                };
            } else {
                return function ($value) use ($method) {
                    return $method->invoke($this->currentUser) == $value;
                };
            }
        }
        if (method_exists($this->currentUser, "get$propertyName")) {
            return function ($value) use ($propertyName) {
                return $this->currentUser->{"get$propertyName"}() == $value;
            };
        }
        if (method_exists($this->currentUser, "get")) {
            return function ($value) use ($propertyName) {
                return $this->currentUser->{"get"}($propertyName) == $value;
            };
        }
        throw new \LogicException("cannot determine value of $propertyName of current user");
    }

    /**
     * @throws AccessDeniedException none of the conditions is met
     * @throws NotLoggedInException if user is not even logged in
     */
    public function check(array $args): void
    {
        // PublicAccess
        if (!$this->conditions) {
            return;
        }

        // RequireLogin
        if (in_array(RequireLogin::class, $this->conditions, true)) {
            if (!$this->currentUser->isLoggedIn()) {
                throw new NotLoggedInException();
            }
            // grant access if there are no other conditions
            if (count($this->conditions) === 1) {
                return;
            }
        }

        // AllowIf
        $conditions = array_filter($this->conditions, 'is_array');
        foreach ($conditions as $condition) {
            foreach ($condition as $propertyName => $valueTemplate) {
                $negate = false;
                if ($valueTemplate && $valueTemplate[0] === '!') {
                    $negate = true;
                    $valueTemplate = substr($valueTemplate, 1);
                }

                $value = $this->evaluateValueTemplate($valueTemplate, $args);
                $result = $this->getValueChecker($propertyName)($value);

                if ($negate) {
                    $result = !$result;
                }
                if (!$result) {
                    // condition is not met, continue with next AllowIf
                    continue 2;
                }
            }
            // AllowIf condition was met
            return;
        }

        // none of the conditions was met
        throw $this->currentUser->isLoggedIn() ? new AccessDeniedException() : new NotLoggedInException();
    }
}
