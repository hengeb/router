<?php
declare(strict_types=1);
namespace Hengeb\Router;

use Hengeb\Router\Attribute\Route;

/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

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
        $this->cacheFile = '/tmp/routes.cache.' . hash('xxh3', $controllerDir) . '.php';
        $this->loadRoutesFromCache();
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    private function loadRoutesFromCache(): void
    {
        $files = $this->collectFiles($this->controllerDir);

        if (!is_file($this->cacheFile) || max($files) >= filemtime($this->cacheFile)) {
            $this->buildRoutes(array_keys($files));
            $this->writeCache();
        } else {
            $this->routes = include($this->cacheFile);
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
            } else {
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
    }

    private function buildRoutes(array $controllerFiles): void
    {
        $this->routes = [];
        $classes = array_map(fn($file) => $this->getClassFromFile($file), $controllerFiles);

        foreach ($classes as $classname) {
            $class = new \ReflectionClass($classname);
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $routes = $method->getAttributes(Route::class);
                foreach ($routes as $route) {
                    $routeInstance = $route->newInstance();
                    // TODO: combine with Route attribute of the class (CONCATENATE matcher and REPLACE allow)
                    $this->add($routeInstance->matcher, $classname, $method->name, $routeInstance->allow, $routeInstance->checkCsrfToken);
                }
            }
        }

        $this->sortRoutes();
    }

    /**
     * @param $matcher is an (optional) HTTP method and URL path like "GET /path/to/the/resource" or "POST /users/{id}" or "/users/{[0-9]+:id}"
     * @param $controller is the classname of a Controller subclass
     * @param $functionName is the function in the controller that will be called
     *                      the function takes the arguments in the same order they appear in the matcher
     * @param $conditions see Route Attribute constructor
     */
    private function add(string $matcher, string $controller, string $functionName, array|bool $conditions, ?bool $checkCsrfToken): void
    {
        $httpMethod = 'GET';
        if (str_contains($matcher, ' ')) {
            [$httpMethod, $matcher] = explode(' ', $matcher, 2);
            $httpMethod = strtoupper($httpMethod);
        }
        [$pathPattern, $queryInfo] = $this->createPattern($matcher);
        $checkCsrfToken ??= $httpMethod !== 'GET';
        $this->routes[$httpMethod . ' ' . $matcher] = [$httpMethod, $pathPattern, $queryInfo, $controller, $functionName, $conditions, $checkCsrfToken];
    }

    private function writeCache(): void
    {
        $fp = fopen($this->cacheFile, 'w');
        fwrite($fp, '<?php return ' . var_export($this->routes, true) . ';');
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
        $matcher = preg_replace('/\(\?P\<(.+?)=\>(.+?)\>/', '(?P<$2__$1>', $matcher);
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
