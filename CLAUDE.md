# CLAUDE.md – hengeb/router

PHP library (Composer package `hengeb/router`, namespace `Hengeb\Router`, PSR-4 from `src/`): attribute-based router with autowiring for controllers, services and models. Requires PHP >= 8.4 (property hooks, `private(set)`) and `symfony/http-foundation`. Latte/Tracy/`Hengeb\Db` are only used in `ServiceContainer` and `LatteExtension` but are not declared in `composer.json`. No tests, no `vendor/`, no build. User documentation: `README.md`. Code comments are in English; every file has `declare(strict_types=1)` and an author/license header.

## Request flow

`Router::dispatch(Request, ?CurrentUserInterface)`:
1. `RouteMap::getRoutes()` returns the routes (cached, see below) in sorted order.
2. Match on HTTP method + path regex, then all query patterns (`$queryInfo`); named matches are collected in `$matches`.
3. If `$checkCsrfToken` is set → `Router::checkCsrfToken()` (token from payload `_csrfToken` or header `X-CSRF-Token`, stored in the session under `csrfTokens`, max. 200 tokens, valid for 1 h, single use). Default: enabled for all methods except GET, overridable with `#[CheckCsrfToken(bool)]`.
4. `DependencyInjector::createObject()` creates the controller (constructor autowiring + `#[Inject]` properties).
5. `Router::call()` determines the `ResponseType` (Json for `Accept: application/json` or return type `array`/`JsonResponse`), resolves the arguments, checks access via `ConditionChecker`, calls the method; `array` → `JsonResponse`, `string` → `Response`.
6. Exceptions (`\Throwable`): `handleException()` on the controller → registered handlers (`addExceptionHandler`, array `[class, method]` or closure) → `defaultExceptionHandler` (plain-text response, status codes 400/401/403/404/500). Every response gets a fresh `X-CSRF-Token` header.

## Files

- `Router.php` – entry point, registers default services (Router, Request, ParameterBag, FileBag, Exception, Throwable, CurrentUserInterface, Session, ResponseType), CSRF, exception handling.
- `RouteMap.php` – finds controllers recursively in `$controllerDir` (class name via `token_get_all`), reads attributes via reflection, builds regexes. Cache: `/tmp/routes.<xxh3(dir)>.cache` (serialize) plus a readable `….cache.php`; rebuilt if a controller file is newer than the cache. Routes without an access attribute → `InvalidRouteException`; `PublicAccess` must not be combined with other access attributes. Sorting: number of query conditions descending (more specific routes first), otherwise the order of the (name-sorted) controller files. Duplicate `METHOD matcher` keys throw `InvalidRouteException`; abstract classes are skipped. The cache is read with `allowed_classes => false` and written atomically.
- `DependencyInjector.php` – types (`addType`, retriever per type + optional identifier name), services (object or closure, then `ServiceContainer`s), `createObject`, `getFunctionArguments`. Argument resolution order: `#[QueryValue]`/`#[RequestValue]` → `UploadedFile` from `FileBag` (by parameter name) → matches from path/query (`$matches`) via retriever (`null`/`[]` → `NotFoundException`) → service by type.
- `ConditionChecker.php` – evaluates `RequireLogin`/`AllowIf`. `AllowIf` values may be templates (`'$user->getId()'`, leading `!` negates) which are parsed via `token_get_all` (simple calls only). Check method on the user: `has<X>`, `is<X>`, `<X>`, `get<X>`, `get('<X>')`. Multiple `AllowIf` = OR, conditions within one `AllowIf` = AND.
- `ServiceContainer.php` – abstract base for projects (Latte, Db, Request, Router, `dispatchRequest()`); `getService()` falls back to `get<ClassBasename>()` methods; throws `OutOfBoundsException` for unknown services (caught by the injector).
- `LatteExtension.php` – Latte filters `timezone`, `format`, functions `csrfToken`, `csrfTokenTag`, `currentUser`, `debugger`, `now`, layout provider `../layout.latte`.
- `Attribute/` – `Route` (repeatable), `PublicAccess`, `RequireLogin`, `AllowIf` (these three implement `AccessAttribute`), `CheckCsrfToken`, `Inject` (property), `QueryValue`/`RequestValue` (parameter). `Exception/` – empty exception classes. `Interface/` – `CurrentUserInterface` (`isLoggedIn`), `RetrievableModel` (`retrieveModel`). `Enum/ResponseType` – Json/Html.

## Route syntax

`'METHOD /path?query'` (method optional, default GET). Placeholders: `{name}`, `{regex:name}`, and the identifier variant `{regex:id=>user}` / `{username=>user}` (the identifier name is encoded internally as `name__identifiedBy__id` and passed to the retriever as `$identifierName`). Regexes in placeholders may contain one level of braces (`{[0-9]{2}:id}`); braces that are no placeholder (`{2,3}`) are left alone. A query parameter without `=` (`?flag`) only has to be present. The character `?` is reserved (path/query separator). Backed enums are resolved automatically via `::from()`.

## Conventions for changes

- Document code changes briefly in the changelog below, no file lists.
- Always mirror public behavior changes (attributes, route syntax) in `README.md`.
- Syntax check: `php -l <file>`.

## Changelog

- Fixed known issues: `getFunctionArguments` fetches the request lazily per call (no more `static` variables); `dispatch` resets `responseType` and catches `\Throwable` (service `\Throwable` registered in addition to `\Exception`; README examples for `handleException` use `\Throwable`); `ob_end_clean` only when buffers are active; error message in `getModelRetriever` corrected; `checkCsrfToken()` without argument; `LatteExtension` creates the UTC timezone lazily and caches timezones in the setter; README namespace corrected.
- Fixed bugs: `!`/`-` in `AllowIf` templates now work (flags were reset before use; `gettype` names were wrong; `$arg` was overwritten); bare query params; nullable types (`?int`, `Foo|null`) via `getTypeName`, unions throw `LogicException`; `#[RequestValue] UploadedFile`; route order is global by query specificity; duplicate routes and missing/empty controller dirs are reported; `getClassFromFile` ignores `Foo::class`/`new class`; `Accept` detection via `getPreferredFormat()`; `ServiceContainer` warning/`getenv` fixes; nested braces and `{n}` quantifiers in route regexes.
- Security: internal (500) exception messages are no longer sent to the client; route cache is not unserialized with classes and is written atomically; CSRF limit raised to 200 tokens, non-string tokens rejected; exceptions in custom exception handlers fall back to the default handler.
- Style: shared `toDateTime()` in `LatteExtension`, tabs → spaces, unused/redundant `use` removed, headers and `strict_types` in `Exception/` and `Interface/`, README typos/examples fixed, `composer.json`: `suggest` for latte/tracy/hengeb-db, `type: library`, `symfony/http-foundation ^7.0 || ^8.0`.
- Open: license of `ServiceContainer`/`LatteExtension` (CC0) differs from the rest (MIT); services added via `addService` closures are not memoized (the router's own request-dependent services must not be).
