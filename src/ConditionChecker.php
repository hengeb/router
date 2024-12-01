<?php
declare(strict_types=1);
namespace Hengeb\Router;

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
     * @return [$invert, $value]
     */
    private function evaluateValueTemplate(string $template, array $args): array
    {
        $invert = false;
        if ($template && $template[0] === '!') {
            $invert = true;
            $template = substr($template, 1);
        }
        if (!$template || $template[0] !== '$') {
            return [$invert, $template];
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

        return [$invert, $value];
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
        if (!$this->conditions) {
            return;
        }

        foreach ($this->conditions as $propertyName => $valueTemplate) {
            // create a two-dimensional array of values
            $valueTemplates = array_map(fn($v) => explode('&', $v), explode('|', (string)$valueTemplate));
            foreach ($valueTemplates as $conjunction) {
                $conjunctionMeets = true;
                foreach ($conjunction as $templateString) {
                    [$invert, $value] = $this->evaluateValueTemplate($templateString, $args);
                    $conjunctionMeets &= $this->getValueChecker($propertyName)($value) ^ $invert;
                    if (!$conjunctionMeets) {
                        break;
                    }
                }
                if ($conjunctionMeets) {
                    return;
                }
            }
        }

        // none of the conditions was met
        throw $this->currentUser->isLoggedIn() ? new AccessDeniedException() : new NotLoggedInException();
    }
}
