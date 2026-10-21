<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router;

use Hengeb\Router\Attribute\AccessAttribute;
use Hengeb\Router\Attribute\AllowIf;
use Hengeb\Router\Attribute\CheckCsrfToken;
use Hengeb\Router\Attribute\PublicAccess;
use Hengeb\Router\Attribute\Route;
use Hengeb\Router\Exception\InvalidRouteException;

/**
 * collects the routes from the controller classes
 */
class RouteMap {
    private string $cacheFile = '';

    /**
     * contains the routes
     * ["METHOD matcher" => [HTTP method, path regex, query info, Controller class, function name, allow rules, check csrf token]]
     */
    private array $routes = [];

    public function __construct(public string $controllerDir)
    {
        $this->cacheFile = '/tmp/routes.' . hash('xxh3', $controllerDir) . '.cache';
    }

    public function setCacheFile(string $cacheFile)
    {
        $this->cacheFile = $cacheFile;
        $this->routes = [];
    }

    public function getRoutes(): array
    {
        if (!$this->routes) {
            $this->loadRoutesFromCacheOrBuild();
        }
        return $this->routes;
    }

    private function loadRoutesFromCacheOrBuild(): void
    {
        $files = $this->collectFiles($this->controllerDir);
        ksort($files);

        if (is_file($this->cacheFile) && ($files === [] || max($files) < filemtime($this->cacheFile))) {
            // the cache only contains scalars and arrays, so no objects are allowed
            $routes = unserialize((string) file_get_contents($this->cacheFile), ['allowed_classes' => false]);
            if (is_array($routes)) {
                $this->routes = $routes;
                return;
            }
        }

        $this->buildRoutes(array_keys($files));
        $this->writeCache();
        $this->writeCacheReadable();
    }

    /**
     * @return array [filename => modified time]
     */
    private function collectFiles(string $directory): array
    {
        $dir = is_dir($directory) ? dir($directory) : false;
        if (!$dir) {
            throw new \InvalidArgumentException("controller directory `$directory` does not exist");
        }
        $files = [];
        while (false !== ($file = $dir->read())) {
            if ($file[0] === '.') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $files = [...$files, ...$this->collectFiles($path)];
            } elseif (str_ends_with($path, '.php')) {
                $files[$path] = filemtime($path);
            }
        }
        $dir->close();
        return $files;
    }

    private function getClassFromFile(string $file): string
    {
        $namespace = '';
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file)),
            fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                $namespace = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] : '';
            } elseif ($token[0] === T_CLASS) {
                $previous = $tokens[$i - 1] ?? null;
                // skip `Foo::class` and `new class`
                if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if (!is_array($next) || $next[0] !== T_STRING) {
                    continue;
                }
                return $namespace ? "$namespace\\{$next[1]}" : $next[1];
            }
        }

        return '';
    }

    private function buildRoutes(array $controllerFiles): void
    {
        $this->routes = [];
        $classes = array_filter(array_map(fn($file) => $this->getClassFromFile($file), $controllerFiles));

        foreach ($classes as $classname) {
            $class = new \ReflectionClass($classname);
            if ($class->isAbstract()) {
                continue;
            }
            $this->addRoutesFromClass($class);
        }

        $this->sortRoutes();
    }

    private function addRoutesFromClass(\ReflectionClass $class): void
    {
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $checkCsrfTokenAttributes = $method->getAttributes(CheckCsrfToken::class);
            $checkCsrfToken = count($checkCsrfTokenAttributes)
                ? $checkCsrfTokenAttributes[0]->newInstance()->check
                : null;

            $accessAttributes = $method->getAttributes(AccessAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            $accessConditions = [];
            foreach ($accessAttributes as $accessAttribute) {
                $accessAttributeInstance = $accessAttribute->newInstance();
                $accessConditions[] = $accessAttributeInstance::class === AllowIf::class
                    ? $accessAttributeInstance->allow
                    : $accessAttributeInstance::class;
            }

            $routes = $method->getAttributes(Route::class);
            foreach ($routes as $route) {
                $routeInstance = $route->newInstance();

                if (count($accessConditions) === 0) {
                    throw new InvalidRouteException("route `{$routeInstance->matcher}` in `{$class->name}::{$method->name}` has no Access attribute. Use PublicAccess or AllowIf or RequireLogin.");
                }
                if (in_array(PublicAccess::class, $accessConditions, true)) {
                    if (count($accessConditions) > 1) {
                        throw new InvalidRouteException("route `{$routeInstance->matcher}` in `{$class->name}::{$method->name}` has the PublicAccess attribute and also other Access attributes.");
                    }
                    $accessConditions = [];
                }

                $this->addRoute($routeInstance->matcher, $class->name, $method->name, $accessConditions, $checkCsrfToken);
            }
        }
    }

    /**
     * @param $matcher is an (optional) HTTP method and URL path like "GET /path/to/the/resource" or "POST /users/{id}" or "/users/{[0-9]+:id}"
     * @param $controller is the classname of a Controller subclass
     * @param $methodName is the method in the controller that will be called
     *                      the method takes the arguments in the same order they appear in the matcher
     * @param $accessConditions configured by attributes
     * @param ?bool wether or not to check the CSRF token (null = auto)
     */
    private function addRoute(
        string $matcher,
        string $controller,
        string $methodName,
        array $accessConditions,
        ?bool $checkCsrfToken,
    ): void {
        $httpMethod = 'GET';
        if (str_contains($matcher, ' ')) {
            [$httpMethod, $matcher] = explode(' ', $matcher, 2);
            $httpMethod = strtoupper($httpMethod);
        }
        [$pathPattern, $queryInfo] = $this->createPattern($matcher);
        $checkCsrfToken ??= $httpMethod !== 'GET';
        $key = $httpMethod . ' ' . $matcher;
        if (isset($this->routes[$key])) {
            throw new InvalidRouteException("route `$key` in `$controller::$methodName` is already defined in `{$this->routes[$key][3]}::{$this->routes[$key][4]}`.");
        }
        $this->routes[$key] = [$httpMethod, $pathPattern, $queryInfo, $controller, $methodName, $accessConditions, $checkCsrfToken];
    }

    private function writeCache(): void
    {
        $this->writeFileAtomically($this->cacheFile, serialize($this->routes));
    }

    private function writeCacheReadable(): void
    {
        $this->writeFileAtomically($this->cacheFile . '.php', "<?php\nreturn " . var_export($this->routes, true) . ";");
    }

    /**
     * write to a temporary file first so concurrent requests never read a half-written file
     */
    private function writeFileAtomically(string $file, string $content): void
    {
        $temporaryFile = tempnam(dirname($file), basename($file) . '.');
        if ($temporaryFile === false || file_put_contents($temporaryFile, $content) === false || !rename($temporaryFile, $file)) {
            if ($temporaryFile !== false) {
                @unlink($temporaryFile);
            }
            throw new \RuntimeException("cannot write route cache file `$file`");
        }
    }

    public function createPattern(string $matcher): array
    {
        $queryInfo = [];
        if (str_contains($matcher, '?')) {
            [$matcher, $queryMatcher] = explode('?', $matcher, 2);
            $queryInfo = $this->createQueryInfo($queryMatcher);
        }

        $matcher = $this->substituteNamedParametersInMatcher($matcher);
        $pathPattern = '(^' . preg_replace('=/+=', '/', '/' . $matcher . '/?') . '$)';

        return [$pathPattern, $queryInfo];
    }

    /**
     * replaces the placeholders `{name}`, `{regex:name}`, `{name=>identifier}` and `{regex:name=>identifier}`
     * by named capture groups (the regex may contain one level of braces like {[0-9]{2}:id});
     * braces that do not form a placeholder, e.g. quantifiers like {2,3}, are left untouched
     */
    private function substituteNamedParametersInMatcher(string $matcher): string
    {
        return preg_replace_callback('/\{((?:[^{}]|\{[^{}]*\})+)\}/', function (array $m): string {
            $namePattern = '[A-Za-z_]\w*(?:=>[A-Za-z_]\w*)?';
            $regex = '[^\/]*?';
            if (preg_match("/^(.*):($namePattern)$/s", $m[1], $parts)) {
                [, $regex, $name] = $parts;
            } elseif (preg_match("/^$namePattern$/", $m[1])) {
                $name = $m[1];
            } else {
                return $m[0];
            }
            if (str_contains($name, '=>')) {
                [$identifier, $name] = explode('=>', $name, 2);
                $name .= '__identifiedBy__' . $identifier;
            }
            return "(?P<$name>$regex)";
        }, $matcher);
    }

    /**
     * @param $queryMatcher is a query string like param1={name1}&param2={[0-9]+:name2}&param3=abc&param4={part1}-{partb}&param5
     * @return queryInfo where keys are the parameter names and the values are regular expressions with named groups (or null if no value is expected)
     */
    private function createQueryInfo(string $queryMatcher): array {
        $queryInfo = [];

        $args = explode('&', $queryMatcher);
        foreach ($args as $key_matcher) {
            if (!str_contains($key_matcher, '=')) {
                $queryInfo[$key_matcher] = null;
                continue;
            }
            [$key, $matcher] = explode('=', $key_matcher, 2);
            $queryInfo[$key] = '(^' . $this->substituteNamedParametersInMatcher($matcher) . '$)';
        }

        return $queryInfo;
    }

    private function sortRoutes(): void {
        // routes with more query conditions are more specific and come first; otherwise preserve the order (uasort is stable)
        uasort($this->routes, fn(array $a, array $b) => count($b[2]) <=> count($a[2]));
    }
}
