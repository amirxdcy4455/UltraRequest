# PHP Ultra Request

A lightweight PHP HTTP client built on cURL. It provides request/response objects, authentication, scoped cookies, proxy support, manual redirect handling, and streamed file downloads.

## Features

- HTTP methods: GET, POST, PUT, PATCH, DELETE, HEAD
- Concurrent HTTP requests using `curl_multi` with a configurable concurrency limit
- Named `RequestPool` collections and independent per-request redirect/error handling
- Lifecycle middleware shared by single and concurrent requests, with request, response, error, and short-circuit hooks
- Mutable fluent Request and Client configuration (no clone-per-method behavior)
- Basic, Bearer, and API Key authentication (header or query placement)
- Redirect handling with method preservation and cross-origin credential protection
- CookieJar with host-only/domain/path/secure matching, Max-Age, persistence, and repeated `Set-Cookie` support
- HTTP, HTTPS, SOCKS4, and SOCKS5 proxies
- Streamed downloads, `.part` temporary files, validated resume, partial-range continuation, retries, and progress callbacks
- JSON response helpers and access to repeated response headers

## Requirements

- PHP 8.0+
- `ext-curl`
- `ext-json`

## Installation

```bash
composer require amirxd/php-ultra-request
```

Or clone the repository and run `composer install`.

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();
$response = $http->get('https://api.github.com/users/amirxd');

if ($response->isSuccessful()) {
    $user = $response->getJsonOrFail();
    echo $user['login'] ?? 'unknown';
}
```

The `UltraRequest` facade also exposes `post()`, `put()`, `patch()`, `delete()`, and `head()`. For full control, use `Client` and `Request` directly.

## Mutable fluent API

All configuration methods mutate the current instance and return the same object. They do **not** clone it.

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = new Client();
$client->withBearerAuth('your-token')->withTimeout(45)->withConnectTimeout(10);

$request = Request::post('https://api.example.com/users');
$request->withHeader('Accept', 'application/json')
        ->withJson(['name' => 'Amir'])
        ->withQueryParams(['source' => 'dashboard']);

$response = $client->send($request);
```

`with...()` naming is retained for API familiarity, but these methods are state-changing. Use a new `Client`, `Request`, or `Downloader` instance when you need an independent configuration.

Request setters such as `setHeader()`, `setBody()`, `setJson()`, `setQueryParams()`, `setFollowRedirects()`, and `setMaxRedirects()` are also available.

## Authentication

```php
$client->withBasicAuth('username', 'password');
$client->withBearerAuth('access-token');
$client->withApiKey('X-API-Key', 'secret-key', 'header');

// API key in the URL query string:
$client->withApiKey('api_key', 'secret-key', 'query');
```

You can also configure `AuthManager` with `withAuth()`:

```php
$client->withAuth(function ($auth): void {
    $auth->bearer('access-token');
});
```

Query-string API keys can appear in server logs, proxy logs, and URL histories. Prefer header placement whenever the remote API supports it.

## Concurrent requests

`Client::sendMany()` executes multiple independent requests concurrently with PHP's cURL multi interface. It does not create PHP threads or processes; libcurl multiplexes the network transfers while PHP manages a bounded set of active handles.

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = new Client();
$client->withTimeout(20);

$results = $client->sendMany([
    'profile' => Request::get('https://api.example.com/profile'),
    'posts' => Request::get('https://api.example.com/posts'),
    'comments' => Request::get('https://api.example.com/comments'),
], concurrency: 3);

foreach ($results as $key => $result) {
    if ($result instanceof \Throwable) {
        printf("%s failed: %s\n", $key, $result->getMessage());
        continue;
    }

    printf("%s returned HTTP %d\n", $key, $result->getStatusCode());
}
```

The result array preserves the original request keys and order. Every value is either an `HttpResponse` or a `Throwable` for that individual request. A DNS, TLS, timeout, or other transport failure for one request does not cancel the remaining requests. HTTP error statuses such as 404 and 500 are normal `HttpResponse` objects; inspect their status codes or use `isClientError()` / `isServerError()`.

For a reusable collection, use `RequestPool`:

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Client\RequestPool;
use Amirxd\UltraRequest\Request\Request;

$pool = new RequestPool();
$pool->add('profile', Request::get('https://api.example.com/profile'));
$pool->add('posts', Request::get('https://api.example.com/posts'));
$pool->addRequest(Request::get('https://api.example.com/notifications'));

$results = (new Client())->sendPool($pool, concurrency: 2);
```

`sendMany()` also accepts a `RequestPool` directly. The concurrency argument is the maximum number of active cURL transfers at a time; it must be at least 1 and defaults to 5. Each request retains its own redirect counter and redirect policy. Cross-origin redirects apply the same credential-stripping rules as `send()`.

Do not use a concurrent pool for requests that depend on the result of another request. A shared CookieJar is supported, but a response cookie can only affect requests that have not started yet; requests already active in the same batch cannot retroactively receive it. Use sequential `send()` calls for login flows or other session-dependent chains. Response bodies are buffered in memory just like regular `send()` responses; use the streamed Downloader for large files.

The `UltraRequest` facade also exposes `sendMany()` and `sendPool()` as convenience methods.

## Middleware system

The client supports a lifecycle-based middleware pipeline shared by `send()`, `sendMany()`, and `RequestPool`. This phase-based design allows middleware to work with both normal requests and `curl_multi` requests without forcing concurrent transfers to run sequentially.

### Registering middleware

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Middleware\AbstractMiddleware;
use Amirxd\UltraRequest\Middleware\RequestContext;
use Amirxd\UltraRequest\Response\HttpResponse;
use Amirxd\UltraRequest\Request\Request;
use Throwable;

$client = new Client();

$client->addMiddleware(new class extends AbstractMiddleware {
    public function before(RequestContext $context): void
    {
        $request = $context->getRequest();

        if ($request instanceof Request) {
            $request->withHeader('X-Application', 'UltraRequest');
        }
    }

    public function after(RequestContext $context, HttpResponse $response): HttpResponse
    {
        // Inspect or replace the response here.
        return $response;
    }

    public function onError(RequestContext $context, Throwable $error): HttpResponse|Throwable
    {
        // Return the error to keep failing, or an HttpResponse to recover.
        return $error;
    }
});

$response = $client->send(Request::get('https://api.example.com/profile'));
```

Implement `MiddlewareInterface` directly when all three hooks are needed. Extend `AbstractMiddleware` when only one or two hooks are needed; its default implementations are no-ops.

### Hook order

If middleware is registered as `Authentication`, `Logging`, and `Cache`, the lifecycle order is:

| Phase | Execution order |
|---|---|
| `before()` | Authentication → Logging → Cache |
| `after()` | Cache → Logging → Authentication |
| `onError()` | Cache → Logging → Authentication |

Use `addMiddleware()` to append middleware or `prependMiddleware()` to put it at the beginning of the pipeline. Use `removeMiddleware($instance)` or `removeMiddleware(FullyQualifiedClassName::class)` to remove it. `clearMiddlewares()` removes all middleware, and `getMiddlewares()` returns the currently registered instances. `withMiddleware()` is an alias for `addMiddleware()`.

Middleware registration methods mutate the existing Client and return the same Client instance. Calling `prependMiddleware()` for an already registered instance moves it to the beginning of the stack instead of registering it twice. The `UltraRequest` facade exposes the same registration and removal methods.

### RequestContext

Every logical request receives its own `RequestContext`, including each item in `sendMany()`. The context survives the request's redirect chain and provides:

- `getOriginalRequest()` — the original RequestInterface supplied by the caller.
- `getRequest()` / `setRequest()` — the current request. Use this to replace a request before it is sent.
- `getKey()` — the original array key for a concurrent request, or `null` for `send()`.
- `setAttribute()`, `getAttribute()`, `hasAttribute()`, `removeAttribute()`, and `getAttributes()` — request-local storage shared between middleware hooks.
- `respond(HttpResponse $response)` — short-circuit the network request and supply a response directly.

Store per-request state in the context, not in properties on a middleware instance. One middleware instance is shared by all requests in a concurrent batch.

### Short-circuiting and caching

A middleware can return a response without opening a cURL handle. This is useful for cache hits, mocks, request blocking, and local routing:

```php
use Amirxd\UltraRequest\Middleware\AbstractMiddleware;
use Amirxd\UltraRequest\Middleware\RequestContext;
use Amirxd\UltraRequest\Response\HttpResponse;

$cacheMiddleware = new class extends AbstractMiddleware {
    public function before(RequestContext $context): void
    {
        $cachedBody = $this->findCachedBody($context->getRequest()->getUrl());

        if ($cachedBody !== null) {
            $context->respond(new HttpResponse(
                $cachedBody,
                "HTTP/1.1 200 OK\r\nX-Cache: HIT\r\n\r\n",
                200
            ));
        }
    }

    private function findCachedBody(string $url): ?string
    {
        // Look up the URL in your cache store.
        return null;
    }
};

$client->addMiddleware($cacheMiddleware);
```

Only middleware whose `before()` hook has been entered participates in the response or error phase of a short-circuited request.

### Error handling and recovery

An `onError()` hook receives transport failures and exceptions raised by middleware hooks. Return the same error or a replacement `Throwable` to keep the request failed. Return an `HttpResponse` to recover it:

```php
public function onError(RequestContext $context, Throwable $error): HttpResponse|Throwable
{
    if ($error instanceof \RuntimeException) {
        return new HttpResponse(
            '{"available":false}',
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
            200
        );
    }

    return $error;
}
```

A response produced by `onError()` is a recovery result; the normal `after()` hooks are not run again for that recovered response. Error hooks themselves run in reverse order. If an error hook throws, its new exception becomes the error passed to the next outer error hook.

### Concurrent requests

The same middleware stack is applied independently to each request in `sendMany()` and `sendPool()`:

- `before()` runs once for each logical request before its transfer is scheduled.
- `after()` runs once when that request's final response arrives, after its redirect chain has finished. It runs as each request completes, not only after the entire pool has drained.
- `onError()` runs for an individual request failure. It can recover one failed item without cancelling other transfers.
- Each request has an independent context and attribute map.
- A middleware short-circuit does not consume a concurrency slot or create a cURL handle.

The pipeline is lifecycle-based rather than a synchronous `next($request)` handler chain. A traditional synchronous handler chain would block while waiting for its downstream handler and would undermine `curl_multi` concurrency. The lifecycle contract keeps the same middleware behavior available to both execution modes.

### Middleware example

A ready-to-adapt logging example is included at `examples/Middleware/LoggingMiddleware.php`. It accepts any callable logger and does not require a PSR logging package. Because the example is outside the production PSR-4 namespace, load it explicitly when trying it:

```php
require __DIR__ . '/examples/Middleware/LoggingMiddleware.php';

$client->addMiddleware(new \Example\Middleware\LoggingMiddleware(
    static function (string $event, array $context): void {
        error_log($event . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
));
```

## Cookies

```php
use Amirxd\UltraRequest\Cookie\CookieJar;

$jar = new CookieJar(__DIR__ . '/private/cookies.json', true);
$jar->setCookieForUrl('https://example.com/account/login', 'session', 'abc123', [
    'httpOnly' => true,
    'secure' => true,
    'sameSite' => 'Lax',
]);

$client->withCookieJar($jar);
$response = $client->send(Request::get('https://example.com/account/home'));
```

`setCookieForUrl()` creates a host-only cookie and calculates the default path from the supplied URL. When using `setCookie()` directly, provide a `domain` option; a cookie without an origin is never treated as a wildcard cookie. Cookies received from servers are scoped to the response host when the server omits `Domain`, and explicit `Domain` attributes are checked against that host. The built-in public-suffix denial list is conservative and covers common suffixes; it is not a full, automatically updated Public Suffix List. For tenant-hosted services, prefer host-only cookies.

Cookie storage files contain session credentials. Keep them outside public web directories; the library attempts to restrict their permissions to the current user.

Use `CookieJar::match($url)` for URL-specific cookies. `toHeader()` is a convenience method that returns all non-expired cookies and is not suitable for sending to an arbitrary host.

## Proxies

```php
use Amirxd\UltraRequest\Proxy\Proxy;

$proxy = new Proxy('socks5://127.0.0.1:1080');
$proxy->withAuth('username', 'password');
$client->setProxy($proxy);
```

Supported schemes: `http`, `https`, `socks4`, and `socks5`. The string representation of a Proxy intentionally omits credentials.

## Redirect behavior

Automatic cURL redirects are disabled. The client follows 301, 302, 303, 307, and 308 responses itself so it can enforce the redirect limit, handle relative `Location` values, and process cookies at each hop.

- 303 changes non-HEAD requests to GET.
- 301/302 change POST to GET.
- 307/308 preserve the method and body.
- The `Host` header is regenerated for every destination.
- Authorization, Cookie, API-key, token, and similar sensitive headers are not forwarded to a different origin. Client authentication and credential-bearing cURL options are also withheld cross-origin.

```php
$request = Request::get('https://example.com/start')->withMaxRedirects(5);
$response = $client->send($request);
```

## File downloads

```php
use Amirxd\UltraRequest\File\Downloader;

$downloader = new Downloader('https://example.com/large-file.zip', __DIR__ . '/downloads/large-file.zip');
$downloader->withResume(true)
           ->withRetries(3, 2)
           ->withChunkSize(256 * 1024)
           ->withProgress(function (array $progress): void {
               printf("%.1f%% - %s / %s\n", $progress['percent'], $progress['downloaded_formatted'], $progress['total_formatted']);
           });

$client->download($downloader);
```

The downloader writes to `destination.part` and renames it to the final path only after completion. Resume requests use `Range` and `Accept-Encoding: identity`; the server's `Content-Range` start, end, total, and actual bytes written are validated. If a server ignores `Range` and returns 200, the partial file is truncated and restarted rather than appended. Servers that return several 206 segments are followed until the advertised total size is reached.

`withRetries($attempts, $delaySeconds)` sets the maximum number of total attempts. Resume is available only when `withResume(true)` is enabled and a `.part` file exists. The console progress bar is opt-in with `withConsoleProgress()`. The Downloader currently uses its dedicated streaming transport path and does not run the regular `MiddlewareInterface` hooks; its file-transfer lifecycle needs separate streaming-oriented hooks rather than a buffered `HttpResponse` pipeline.

## Response helpers

```php
$response->getStatusCode();
$response->isSuccessful();
$response->getHeader('Content-Type');
$response->getHeaders();
$response->getHeaderValues('Set-Cookie'); // all occurrences
$response->getJson();
$response->getJsonOrFail(); // throws JsonException on invalid JSON
$response->getJsonError();
$response->getInfo(); // cURL transfer info
```

`getHeaders()` keeps a simple associative representation; when a header can occur more than once, use `getHeaderValues()`.

## Custom cURL options

```php
$client->withCurlOption(CURLOPT_USERAGENT, 'MyApplication/1.0');
$client->withCurlOption(CURLOPT_REFERER, 'https://example.com/');
```

Options that override the request URL, header/cookie handling, redirect handling, response buffering, or internal streaming callbacks are reserved by the library and rejected. Credential-bearing cURL options are not applied to a different origin during redirects.

## Tests

Run the local test suite:

```bash
php tests/run.php
```

The suite uses a deterministic cURL test double, so logic tests can run even on a development machine without `ext-curl`. Real HTTP integration still requires the cURL extension and a reachable test server.

## Project structure

```text
src/
├── Auth/
├── Client/
│   ├── MultiRequestExecutor.php
│   ├── MultiRequestStep.php
│   └── RequestPool.php
├── Cookie/
├── File/
├── Middleware/
│   ├── AbstractMiddleware.php
│   ├── MiddlewareInterface.php
│   ├── MiddlewarePipeline.php
│   └── RequestContext.php
├── Proxy/
├── Request/
├── Response/
└── UltraRequest.php

examples/
└── Middleware/
    └── LoggingMiddleware.php

tests/
└── run.php
```

## License

MIT. See [LICENSE](LICENSE).
