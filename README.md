# Hengeb\Router

a PHP router that makes use of attributes and provides autowiring

## example usage

Make sure your HTTP server redirects alle requests to `index.php` (the front controller).

example nginx directive:

```
...
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
...
```

In `index.php`, create the Router object and dispatch the request:

```php
use App\Service\Router\Router;
use Symfony\Component\HttpFoundation\Request;

require_once '../vendor/autoload.php';

// create the Router object and pass it the directory with your controllers (will also look in subdirectories)
$router = new Router(__DIR__ . '/../Classes/Controller');
$request = Request::createFromGlobals();
$router->dispatch($request)->send();
```

The router will look for a controller with a Route attribute that matches the router. The router will cache the routes and only re-analyze the controllers when a file has changed.

```php
use Symfony\Component\HttpFoundation\Response;
use App\Service\Router\Attribute\Route;
use Hengeb\Db\Db;

class MyController extends Controller {
    // the route matcher starts with the HTTP method, followed by the path. You can use some regular expressions to some extend
    // do not use the ? symbol because this separates the query from the path)
    // this will match /search and / and also /search/ and also /search?foo=bar but not /search?q=theQuery because of the next route
    #[Route('GET /(search|)', allow: true)]
    public function form(): Response {
        return $this->render('SearchController/search');
    }

    // you can use identifiers in the path or in the query, they will be passed as arguments and casted to the desired type
    // the Db object will be injected (in this case via the Db::getInstance() method)
    #[Route('GET /(search|)?q={query}', allow: true)]
    public function search(string $query, Db $db): Response {
        $sql = $this->buildQuery($query);
        $results = $db->query($sql);
        return $this->showResults($ids);
    }

    // access control: only allow logged-in users (in the above examples: allow public access)
    // the dispatch method needs an object that implements the CurrentUserInterface
    #[Route('GET /users', allow: ['loggedIn' => true])]
    public function users(): Response {
        ...
    }

    // you can have multiple route matchers, make sure they fit together
    // \d+ regex for number, get the user object by its id
    #[Route('GET /user/{\d+:id=>user}', allow: ['loggedIn' => true])]
    // if the above matcher does not fit, get the user by its username
    #[Route('GET /user/{username=>user}', allow: ['loggedIn' => true])]
    // the user object will be fetched from the database and injected
    public function show(User $user): Response {
        ...
    }

    // access control: only give access if the current user has the admin role OR is the user himself/herself
    // this will look for a method like hasRole, getRole, isRole, role, get("role") and so on
    // the '$user->get("id")' is a template string that will be evaluated. This does only allow simple function calls because the string will be parsed.
    #[Route('GET /user/{username=>user}/edit', allow: ['role' => 'admin', 'id' => '$user->get("id")'])]
    public function edit(User $user, CurrentUser $currentUser): Response {
        ...
    }

    // this route will only match if the method is POST
    // this will also check for a POST variable _csrf_token and validate it to prevent CSRF attacks
    // you can override this by passing `checkCsrfToken: false` to the Route attribute
    // generate the CSRF token with $router->createCsrfToken()
    #[Route('POST /login', allow: true)]
    public function login() {

    }

    // you can inject the router object, the request, the current user
    #[Route('GET /foo', allow: true)]
    public function foo() {
        ...
    }
}
```

## Model injection

You can tell the Router object how to retrieve an object of a given type:

```php
$router->addType(User::class, fn($id) => User::find($id), 'id')
```

The third parameter is optional. Use it if there are multiple ways to find the target.

Alternatively you can add a `getRepository` method to your model class. The Router object will then try to figure how to get the model.

```php
class User {
    public function getRepository {
        return UserRepository::class;
    }
}

class UserRepository {
    public function findOneById(int $id): User {
        ...
    }
}
```

Router will look for a method like `findById`, `findOneById`, `getById`, `find`, `getBy('id', ...)` and so on (or replace 'id' by 'username' or however the identifier in the router is called).

## Service injection

You can tell the Router how to retrieve a service object:

```php
$templateEngine = new TemplateEngine();
$router->addService(TemplateEngine::class, $templateEngine);
```

Or using a closure so objects will only be created when they are needed:

```
$router->addService(TemplateEngine::class, fn() => new TemplateEngine());
```

Or the Router object will try to find the service automatically, either by calling `$className::getInstance()` or by creating a new object (with service injection in the constructor, be careful so you don't end up in endless recursion)

## Exception handling

If something goes wrong a default error page will be shown with a short description of the error and the according HTTP status code.

You can add a method `handleException(\Exception $e, ...)` (other dependcies will be injected) to your controller to handle the exception.

However, this will not work if no controller could be determined because no route matches request. To cover this case you can add a custom
exception handler:

```php
$router->addExceptionHandler(InvalidRouteException::class, [Controller::class, 'handleException']);
```

The class will be created with service injection.

Alternatively you can use a closure:

```php
$router->addExceptionHandler(InvalidRouteException::class, fn(\Exception $e) => (new Controller)->handleException($e));
```
