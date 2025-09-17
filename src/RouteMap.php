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
     * [matcher  => [HTTP method, path regex, query info, Controller class, function name, allow rules, check csrf token]]
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

        if (!is_file($this->cacheFile) || max($files) >= filemtime($this->cacheFile)) {
            $this->buildRoutes(array_keys($files));
            $this->writeCache();
            $this->writeCacheReadable();
        } else {
            $this->routes = unserialize(file_get_contents($this->cacheFile));
        }
    }

    /**
     * @return array [filename => modified time]
     */
    private function collectFiles(string $directory): array
    {
        $dir = dir($directory);
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
        $tokens = token_get_all(file_get_contents($file));

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_NAMESPACE) {
                $namespace = $tokens[$i+2][1];
            } elseif ($token[0] === T_CLASS) {
                $class = $tokens[$i+2][1];
                return $namespace ? "$namespace\\$class" : $class;
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
                    throw new InvalidRouteException("route `{$routeInstance->matcher}` in `{$class->name}::{$method->name}` has no Access attribute. Use PublicAccess or RestrictedAccess or RequireLogin.");
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
        $this->routes[$httpMethod . ' ' . $matcher] = [$httpMethod, $pathPattern, $queryInfo, $controller, $methodName, $accessConditions, $checkCsrfToken];
    }

    private function writeCache(): void
    {
        $fp = fopen($this->cacheFile, 'w');
        fwrite($fp, serialize($this->routes));
        fclose($fp);
    }

    private function writeCacheReadable(): void
    {
        $fp = fopen($this->cacheFile . '.php', 'w');
        fwrite($fp, "<?php\nreturn " . var_export($this->routes, true) . ";");
        fclose($fp);
    }

    public function createPattern(string $matcher) {
        $queryInfo = [];
        if (str_contains($matcher, '?')) {
            [$matcher, $queryMatcher] = explode('?', $matcher, 2);
            $queryInfo = $this->createQueryInfo($queryMatcher);
        }

        $matcher = $this->substituteNamedParametersInMatcher($matcher);
        $pathPattern = '(^' . preg_replace('=/+=', '/', '/' . $matcher . '/?') . '$)';

        return [$pathPattern, $queryInfo];
    }

    private function substituteNamedParametersInMatcher(string $matcher): string {
        $matcher = preg_replace('/\{([^:]+?)\}/', '(?P<$1>[^\/]*?)', $matcher);
        $matcher = preg_replace('/\{(.+?):(.+?)\}/', '(?P<$2>$1)', $matcher);
        $matcher = preg_replace('/\(\?P\<(.+?)=\>(.+?)\>/', '(?P<$2__identifiedBy__$1>', $matcher);
        return $matcher;
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
            $matcher = $this->substituteNamedParametersInMatcher($matcher);
            $pattern = '(^' . $matcher . '$)';
            $queryInfo[$key] = $this->substituteNamedParametersInMatcher($pattern);
        }

        return $queryInfo;
    }

    private function sortRoutes(): void {
        // sort by controller name, query info length DESC, preserve pre-order
        uasort($this->routes, function (array $a, array $b) {
            return $a[3] <=> $b[3] ?: count($b[2]) <=> count($a[2]);
        });
    }
}
