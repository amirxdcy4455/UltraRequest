<?php

declare(strict_types=1);

namespace {
    class CurlHandle
    {
        public array $options = [];
        public array $info = [];
        public int $errno = 0;
        public string $error = '';
        public string|false $content = '';
        public bool $executed = false;
    }

    class CurlMultiHandle
    {
        public array $handles = [];
        public array $completed = [];
        public array $options = [];
        public int $maxActive = 0;
    }

    foreach ([
        'CURLOPT_URL' => 10002,
        'CURLOPT_RETURNTRANSFER' => 19913,
        'CURLOPT_HEADER' => 42,
        'CURLOPT_CONNECTTIMEOUT' => 78,
        'CURLOPT_TIMEOUT' => 13,
        'CURLOPT_SSL_VERIFYPEER' => 64,
        'CURLOPT_SSL_VERIFYHOST' => 81,
        'CURLOPT_ENCODING' => 10102,
        'CURLOPT_NOBODY' => 44,
        'CURLOPT_HTTPGET' => 80,
        'CURLOPT_POST' => 47,
        'CURLOPT_CUSTOMREQUEST' => 10036,
        'CURLOPT_POSTFIELDS' => 10015,
        'CURLOPT_HTTPHEADER' => 10023,
        'CURLOPT_COOKIE' => 10022,
        'CURLOPT_FOLLOWLOCATION' => 52,
        'CURLOPT_UNRESTRICTED_AUTH' => 105,
        'CURLOPT_WRITEFUNCTION' => 20011,
        'CURLOPT_HEADERFUNCTION' => 20079,
        'CURLOPT_BUFFERSIZE' => 98,
        'CURLOPT_NOPROGRESS' => 43,
        'CURLOPT_XFERINFOFUNCTION' => 20219,
        'CURLOPT_PROXY' => 10004,
        'CURLOPT_PROXYPORT' => 59,
        'CURLOPT_PROXYTYPE' => 101,
        'CURLOPT_PROXYUSERPWD' => 10006,
        'CURLOPT_USERAGENT' => 10018,
        'CURLOPT_REFERER' => 10016,
        'CURLPROXY_HTTP' => 0,
        'CURLPROXY_SOCKS4' => 4,
        'CURLPROXY_SOCKS5' => 5,
        'CURLOPT_NETRC' => 51,
        'CURLOPT_USERPWD' => 10005,
        'CURLOPT_PROXY' => 10004,
        'CURLOPT_PROXYPORT' => 59,
        'CURLOPT_PROXYTYPE' => 101,
        'CURLM_OK' => 0,
        'CURLM_CALL_MULTI_PERFORM' => -1,
        'CURLMSG_DONE' => 1,
        'CURLMOPT_MAX_TOTAL_CONNECTIONS' => 13,
    ] as $constant => $value) {
        if (!defined($constant)) define($constant, is_int($value) ? $value : 10298);
    }

    final class FakeCurl
    {
        public static array $fixtures = [];
        public static array $failures = [];
        public static array $requests = [];
        public static int $maxConcurrentHandles = 0;

        public static function reset(): void
        {
            self::$fixtures = [];
            self::$failures = [];
            self::$requests = [];
            self::$maxConcurrentHandles = 0;
        }

        public static function set(string $url, int $status, array $headers = [], string $body = ''): void
        {
            self::$fixtures[$url] = [$status, $headers, $body];
        }

        public static function setSequence(string $url, array $responses): void
        {
            self::$fixtures[$url] = ['sequence' => array_values($responses)];
        }

        public static function fail(string $url, int $code = 7, string $message = 'Connection failed'): void
        {
            self::$failures[$url] = [$code, $message];
        }

        public static function fetch(string $url): array
        {
            $fixture = self::$fixtures[$url] ?? null;
            if (is_array($fixture) && isset($fixture['sequence'])) {
                $response = array_shift($fixture['sequence']);
                self::$fixtures[$url] = $fixture;
                return $response ?? [500, [], 'No more mock responses'];
            }
            return $fixture ?? [200, ['Content-Type' => 'text/plain'], 'ok'];
        }
    }
}

namespace Amirxd\UltraRequest\Client {
    function extension_loaded(string $extension): bool
    {
        return $extension === 'curl' || \extension_loaded($extension);
    }

    function curl_init(?string $url = null): \CurlHandle|false
    {
        return new \CurlHandle();
    }

    function curl_setopt(\CurlHandle $handle, int $option, mixed $value): bool
    {
        $handle->options[$option] = $value;
        return true;
    }

    function curl_exec(\CurlHandle $handle): string|bool
    {
        $url = (string)($handle->options[\CURLOPT_URL] ?? '');
        \FakeCurl::$requests[] = ['url' => $url, 'options' => $handle->options];

        if (isset(\FakeCurl::$failures[$url])) {
            [$handle->errno, $handle->error] = \FakeCurl::$failures[$url];
            return false;
        }

        [$status, $headers, $body] = \FakeCurl::fetch($url);
        $statusText = match ($status) {
            200 => 'OK', 206 => 'Partial Content', 301 => 'Moved Permanently', 302 => 'Found',
            303 => 'See Other', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            416 => 'Range Not Satisfiable', 404 => 'Not Found', default => 'Mock Response',
        };
        $rawHeaders = "HTTP/1.1 {$status} {$statusText}\r\n";
        $lines = [];
        foreach ($headers as $name => $value) {
            foreach ((array)$value as $singleValue) {
                $line = $name . ': ' . $singleValue . "\r\n";
                $rawHeaders .= $line;
                $lines[] = $line;
            }
        }
        $rawHeaders .= "\r\n";
        $headerCallback = $handle->options[\CURLOPT_HEADERFUNCTION] ?? null;
        $writeCallback = $handle->options[\CURLOPT_WRITEFUNCTION] ?? null;

        if (is_callable($headerCallback)) {
            if ($headerCallback($handle, "HTTP/1.1 {$status} {$statusText}\r\n") === 0) { $handle->errno = 23; $handle->error = 'Mock header callback failed'; return false; }
            foreach ($lines as $line) { if ($headerCallback($handle, $line) === 0) { $handle->errno = 23; $handle->error = 'Mock header callback failed'; return false; } }
            if ($headerCallback($handle, "\r\n") === 0) { $handle->errno = 23; $handle->error = 'Mock header callback failed'; return false; }
        }
        if (is_callable($writeCallback) && $body !== '') {
            $result = $writeCallback($handle, $body);
            if ($result === 0) {
                $handle->errno = 23;
                $handle->error = 'Mock write callback failed';
                return false;
            }
        }
        $progressOption = defined('CURLOPT_XFERINFOFUNCTION') ? CURLOPT_XFERINFOFUNCTION : (defined('CURLOPT_PROGRESSFUNCTION') ? CURLOPT_PROGRESSFUNCTION : null);
        $progressCallback = $progressOption !== null ? ($handle->options[$progressOption] ?? null) : null;
        if (is_callable($progressCallback)) {
            $progressCallback($handle, strlen($body), strlen($body), 0, 0);
        }

        $handle->info = [
            'url' => $url,
            'http_code' => $status,
            'header_size' => strlen($rawHeaders),
            'total_time' => 0.01,
            'content_type' => $headers['Content-Type'] ?? null,
        ];

        if (is_callable($writeCallback)) return true;
        return ($handle->options[\CURLOPT_HEADER] ?? false) ? $rawHeaders . $body : $body;
    }

    function curl_getinfo(\CurlHandle $handle): array { return $handle->info; }
    function curl_error(\CurlHandle $handle): string { return $handle->error; }
    function curl_errno(\CurlHandle $handle): int { return $handle->errno; }
    function curl_close(\CurlHandle $handle): void {}

    function curl_multi_init(): \CurlMultiHandle|false
    {
        return new \CurlMultiHandle();
    }

    function curl_multi_setopt(\CurlMultiHandle $multiHandle, int $option, mixed $value): bool
    {
        $multiHandle->options[$option] = $value;
        return true;
    }

    function curl_multi_add_handle(\CurlMultiHandle $multiHandle, \CurlHandle $handle): int
    {
        $multiHandle->handles[spl_object_id($handle)] = $handle;
        $multiHandle->maxActive = max($multiHandle->maxActive, count($multiHandle->handles));
        \FakeCurl::$maxConcurrentHandles = max(
            \FakeCurl::$maxConcurrentHandles,
            count($multiHandle->handles)
        );
        return CURLM_OK;
    }

    function curl_multi_exec(\CurlMultiHandle $multiHandle, int &$running): int
    {
        foreach ($multiHandle->handles as $handle) {
            if ($handle->executed) {
                continue;
            }

            $handle->executed = true;
            $handle->content = curl_exec($handle);
            $multiHandle->completed[] = [
                'msg' => CURLMSG_DONE,
                'result' => $handle->errno,
                'handle' => $handle,
            ];
        }

        $running = count($multiHandle->handles);
        return CURLM_OK;
    }

    function curl_multi_info_read(\CurlMultiHandle $multiHandle): array|false
    {
        return array_shift($multiHandle->completed) ?: false;
    }

    function curl_multi_getcontent(\CurlHandle $handle): string|false
    {
        return $handle->content;
    }

    function curl_multi_remove_handle(\CurlMultiHandle $multiHandle, \CurlHandle $handle): int
    {
        unset($multiHandle->handles[spl_object_id($handle)]);
        return CURLM_OK;
    }

    function curl_multi_select(\CurlMultiHandle $multiHandle, float $timeout = 1.0): int
    {
        return $multiHandle->completed !== [] ? 1 : 0;
    }

    function curl_multi_close(\CurlMultiHandle $multiHandle): void {}
    function curl_multi_strerror(int $code): string { return 'Mock multi error'; }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Amirxd\\UltraRequest\\';
        if (!str_starts_with($class, $prefix)) return;
        $relative = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require $file;
    });

    use Amirxd\UltraRequest\Auth\AuthManager;
    use Amirxd\UltraRequest\Client\Client;
    use Amirxd\UltraRequest\Cookie\Cookie;
    use Amirxd\UltraRequest\Cookie\CookieJar;
    use Amirxd\UltraRequest\File\Downloader;
    use Amirxd\UltraRequest\Proxy\Proxy;
    use Amirxd\UltraRequest\Request\Request;
    use Amirxd\UltraRequest\Response\HttpResponse;

    $passed = 0;
    $failed = 0;
    $test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
        try {
            $callback();
            $passed++;
            echo "[PASS] {$name}\n";
        } catch (\Throwable $error) {
            $failed++;
            echo "[FAIL] {$name}: {$error->getMessage()}\n";
        }
    };
    $assert = static function (bool $condition, string $message = 'Assertion failed'): void {
        if (!$condition) throw new \RuntimeException($message);
    };
    $throws = static function (callable $callback, string $class = \Throwable::class) use ($assert): void {
        try { $callback(); }
        catch (\Throwable $error) { $assert($error instanceof $class, 'Unexpected exception: ' . get_class($error)); return; }
        throw new \RuntimeException('Expected exception was not thrown.');
    };

    $test('Concurrent requests preserve keys and respect the concurrency limit', static function () use ($assert): void {
        \FakeCurl::reset();

        foreach (['one', 'two', 'three', 'four', 'five'] as $name) {
            \FakeCurl::set('https://multi.example.test/' . $name, 200, [], $name);
        }

        $client = new Client();
        $results = $client->sendMany([
            'first' => Request::get('https://multi.example.test/one'),
            'second' => Request::get('https://multi.example.test/two'),
            'third' => Request::get('https://multi.example.test/three'),
            'fourth' => Request::get('https://multi.example.test/four'),
            'fifth' => Request::get('https://multi.example.test/five'),
        ], 2);

        $assert(array_keys($results) === ['first', 'second', 'third', 'fourth', 'fifth']);
        $assert($results['first'] instanceof HttpResponse);
        $assert($results['third']->getBody() === 'three');
        $assert($results['fifth']->getBody() === 'five');
        $assert(count(\FakeCurl::$requests) === 5);

        $assert(\FakeCurl::$maxConcurrentHandles === 2);
    });

    $test('Concurrent requests can be executed from a RequestPool', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://pool.example.test/profile', 200, [], 'profile');
        \FakeCurl::set('https://pool.example.test/posts', 200, [], 'posts');

        $pool = new \Amirxd\UltraRequest\Client\RequestPool();
        $pool->add('profile', Request::get('https://pool.example.test/profile'));
        $pool->add('posts', Request::get('https://pool.example.test/posts'));

        $results = (new Client())->sendPool($pool, 2);

        $assert($results['profile'] instanceof HttpResponse);
        $assert($results['posts'] instanceof HttpResponse);
        $assert($results['profile']->getBody() === 'profile');
        $assert($results['posts']->getBody() === 'posts');
    });

    $test('Concurrent requests continue independently after a transport failure', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::fail('https://multi.example.test/fails', 7, 'Connection refused');
        \FakeCurl::set('https://multi.example.test/works', 200, [], 'available');

        $results = (new Client())->sendMany([
            'failed' => Request::get('https://multi.example.test/fails'),
            'successful' => Request::get('https://multi.example.test/works'),
        ], 2);

        $assert($results['failed'] instanceof \RuntimeException);
        $assert(str_contains($results['failed']->getMessage(), 'Connection refused'));
        $assert($results['successful'] instanceof HttpResponse);
        $assert($results['successful']->getBody() === 'available');
    });

    $test('Concurrent cross-origin redirects do not leak client authentication', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://trusted.example.test/start', 302, ['Location' => 'https://external.example.test/collect']);
        \FakeCurl::set('https://external.example.test/collect', 200, [], 'received');

        $client = (new Client())->withBearerAuth('private-token');
        $results = $client->sendMany([
            'redirect' => Request::get('https://trusted.example.test/start'),
        ], 1);

        $assert($results['redirect'] instanceof HttpResponse);
        $assert(count(\FakeCurl::$requests) === 2);
        $firstHeaders = \FakeCurl::$requests[0]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $secondHeaders = \FakeCurl::$requests[1]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(in_array('Authorization: Bearer private-token', $firstHeaders, true));
        $assert(!in_array('Authorization: Bearer private-token', $secondHeaders, true));
    });

    $test('Concurrent request redirects are followed independently', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://multi.example.test/start', 302, ['Location' => '/finish']);
        \FakeCurl::set('https://multi.example.test/finish', 200, [], 'redirected');
        \FakeCurl::set('https://multi.example.test/other', 200, [], 'other');

        $results = (new Client())->sendMany([
            'redirected' => Request::get('https://multi.example.test/start'),
            'other' => Request::get('https://multi.example.test/other'),
        ], 2);

        $assert($results['redirected'] instanceof HttpResponse);
        $assert($results['redirected']->getBody() === 'redirected');
        $assert($results['other'] instanceof HttpResponse);
        $assert($results['other']->getBody() === 'other');
        $assert(count(\FakeCurl::$requests) === 3);
    });

    $test('Concurrent requests enforce redirect limits per request', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://multi.example.test/loop', 302, ['Location' => '/loop']);
        \FakeCurl::set('https://multi.example.test/healthy', 200, [], 'ok');

        $results = (new Client())->sendMany([
            'loop' => Request::get('https://multi.example.test/loop')->withMaxRedirects(1),
            'healthy' => Request::get('https://multi.example.test/healthy'),
        ], 2);

        $assert($results['loop'] instanceof \RuntimeException);
        $assert(str_contains($results['loop']->getMessage(), 'Maximum redirects'));
        $assert($results['healthy'] instanceof HttpResponse);
        $assert($results['healthy']->getBody() === 'ok');
    });

    $test('RequestPool supports named and generated requests', static function () use ($assert, $throws): void {
        $pool = new \Amirxd\UltraRequest\Client\RequestPool();
        $pool->add('profile', Request::get('https://pool.example.test/profile'));
        $generatedKey = $pool->addRequest(Request::get('https://pool.example.test/posts'));

        $assert($pool->count() === 2);
        $assert($pool->has('profile'));
        $assert($generatedKey === 'request_1');
        $throws(
            static fn() => $pool->add('profile', Request::get('https://pool.example.test/duplicate')),
            \InvalidArgumentException::class
        );
    });

    $test('Concurrent request validation rejects invalid concurrency and request values', static function () use ($throws): void {
        $client = new Client();
        $throws(static fn() => $client->sendMany([], 0), \InvalidArgumentException::class);
        $throws(static fn() => $client->sendMany(['invalid' => 'not a request']), \InvalidArgumentException::class);
    });

    $test('Request mutators update the same object and preserve URL components', static function () use ($assert): void {
        $request = Request::get('https://user:p%40ss@example.test/api?keep=1#section');
        $same = $request;
        $request->withHeader('X-Test', 'yes')->withQueryParams(['page' => 2]);
        $assert($request === $same, 'Request instance was replaced.');
        $assert($request->getUrl() === 'https://user:p%40ss@example.test/api?keep=1&page=2#section', $request->getUrl());
        $duplicateQuery = Request::get('https://example.test/?tag=one&tag=two&keep=a%2Fb')->withQueryParams(['page' => 2]);
        $assert($duplicateQuery->getUrl() === 'https://example.test/?tag=one&tag=two&keep=a%2Fb&page=2', $duplicateQuery->getUrl());
        $replaceQuery = Request::get('https://example.test/?tag=one&tag=two')->withQueryParams(['tag' => 'three']);
        $assert($replaceQuery->getUrl() === 'https://example.test/?tag=three', $replaceQuery->getUrl());
    });

    $test('Request JSON encoding and header validation', static function () use ($assert, $throws): void {
        $request = Request::post('https://example.test/api')->withJson(['name' => 'Amir']);
        $assert(($request->getHeaders()['Content-Type'] ?? '') === 'application/json');
        $assert(json_decode((string)$request->getBody(), true)['name'] === 'Amir');
        $throws(static fn() => $request->withHeader('X-Test', "ok\r\nInjected: yes"), \InvalidArgumentException::class);
        $throws(static fn() => $request->withJson(["bad" => "\xB1\x31"]), \JsonException::class);
    });

    $test('Cookie host-only, domain, path and secure matching', static function () use ($assert): void {
        $hostOnly = new Cookie('sid', 'host', ['domain' => 'example.test', 'hostOnly' => true, 'path' => '/account']);
        $assert($hostOnly->matches('https://example.test/account/profile'));
        $assert(!$hostOnly->matches('https://sub.example.test/account/profile'));
        $assert(!$hostOnly->matches('https://example.test/accounting'));
        $domainCookie = new Cookie('sid', 'domain', ['domain' => 'example.test', 'path' => '/', 'secure' => true]);
        $assert($domainCookie->matches('https://sub.example.test/'));
        $assert(!$domainCookie->matches('http://sub.example.test/'));
    });

    $test('CookieJar parses Domain, Max-Age and keeps host-only cookies scoped', static function () use ($assert): void {
        $jar = new CookieJar(null);
        $jar->fromString('host=one; Path=/account', 'example.test', '/account/login');
        $jar->fromString('domain=two; Domain=example.test; Path=/', 'api.example.test');
        $jar->fromString('gone=x; Max-Age=0', 'example.test');
        $assert(count($jar->match('https://example.test/account')) === 2);
        $assert(count($jar->match('https://example.test/accounting')) === 1);
        $assert(count($jar->match('https://sub.example.test/account')) === 1);
        $assert(!$jar->hasCookie('gone'));
        $jar->fromString('bad=x; Domain=com', 'example.com');
        $assert(!$jar->hasCookie('bad'));
        $assert($jar->getCookie('host')?->getPath() === '/account');
    });

    $test('Cookie domain attributes reject common public suffixes and invalid values', static function () use ($assert, $throws): void {
        $jar = new CookieJar(null);
        $jar->fromString('bad=x; Domain=co.uk', 'shop.co.uk');
        $assert(!$jar->hasCookie('bad'));
        $throws(static fn() => new Cookie('bad', 'value with spaces', ['domain' => 'example.test']), \InvalidArgumentException::class);
        $quoted = new Cookie('quoted', '"value-with-dash"', ['domain' => 'example.test']);
        $assert($quoted->getValue() === '"value-with-dash"');
        $throws(static fn() => new Cookie('bad', 'x', ['domain' => 'com']), \InvalidArgumentException::class);
    });

    $test('Response preserves repeated Set-Cookie headers', static function () use ($assert): void {
        $response = new HttpResponse('ok', "HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Type: text/plain\r\n\r\n", 200);
        $values = $response->getHeaderValues('set-cookie');
        $assert(count($values) === 2);
        $assert($values[0] === 'a=1' && $values[1] === 'b=2');
        $assert($response->getHeader('Set-Cookie') === 'b=2');
    });

    $test('Response preserves zero-valued headers', static function () use ($assert): void {
        $response = new HttpResponse('', "HTTP/1.1 200 OK\r\nX-Count: 0\r\n\r\n", 200);
        $assert($response->getHeader('X-Count') === '0');
        $assert(($response->getHeaders()['x-count'] ?? null) === '0');
    });

    $test('Downloader mutators update the same object and validate chunk size', static function () use ($assert, $throws): void {
        $downloader = new Downloader('https://files.example.test/file.bin', sys_get_temp_dir() . '/ultra-test-' . uniqid() . '.bin');
        $same = $downloader;
        $downloader->withResume()->withChunkSize(4096)->withRetries(2, 0)->withHeader('X-Test', 'yes');
        $assert($downloader === $same);
        $assert($downloader->getChunkSize() === 4096);
        $throws(static fn() => $downloader->withChunkSize(0), \InvalidArgumentException::class);
    });

    $test('Proxy mutates in place and never exposes credentials in string output', static function () use ($assert): void {
        $proxy = new Proxy('socks5://alice:super-secret@proxy.example.test:1080');
        $same = $proxy;
        $proxy->withAuth('bob', 'another-secret');
        $assert($proxy === $same);
        $assert(!str_contains((string)$proxy, 'another-secret'));
        $assert(!str_contains((string)$proxy, 'bob'));
    });

    $test('Proxy validates credentials and formats IPv6 hosts for cURL', static function () use ($assert, $throws): void {
        $proxy = new Proxy('socks5://[2001:db8::1]:1080');
        $options = $proxy->getCurlOptions();
        $assert(($options[\CURLOPT_PROXY] ?? null) === '[2001:db8::1]');
        $assert((string)$proxy === 'socks5://[2001:db8::1]:1080');
        $throws(static fn() => $proxy->withAuth('bad:user', 'secret'), \InvalidArgumentException::class);
        $throws(static fn() => new Proxy('http://user%0Aevil:pass@proxy.example.test:8080'), \InvalidArgumentException::class);
    });

    $test('AuthManager exposes API key query parameters', static function () use ($assert): void {
        $manager = (new AuthManager())->apiKey('api_key', 'secret', 'query');
        $assert($manager->getQueryParams() === ['api_key' => 'secret']);
        $assert($manager->getHeaders() === []);
    });

    $test('Client does not forward authorization to a different origin', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://origin.example.test/start', 302, ['Location' => 'https://elsewhere.example.test/landing'], 'redirect');
        \FakeCurl::set('https://elsewhere.example.test/landing', 200, ['Content-Type' => 'text/plain'], 'ok');
        $client = (new Client())->withBearerAuth('do-not-leak');
        $response = $client->send(Request::get('https://origin.example.test/start'));
        $assert($response->getStatusCode() === 200);
        $firstHeaders = \FakeCurl::$requests[0]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $secondHeaders = \FakeCurl::$requests[1]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(in_array('Authorization: Bearer do-not-leak', $firstHeaders, true));
        $assert(!in_array('Authorization: Bearer do-not-leak', $secondHeaders, true));
    });

    $test('Client enforces redirect limit without recursive counter reset', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://loop.example.test/path', 302, ['Location' => '/path'], 'redirect');
        $client = new Client();
        try {
            $client->send(Request::get('https://loop.example.test/path')->withMaxRedirects(1));
            throw new \RuntimeException('Redirect limit was not enforced.');
        } catch (\RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'Maximum redirects'));
        }
        $assert(count(\FakeCurl::$requests) === 2, 'Unexpected number of requests: ' . count(\FakeCurl::$requests));
    });

    $test('Client preserves GET-with-body semantics and rejects protected cURL overrides', static function () use ($assert, $throws): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://method.example.test/body', 200, [], 'ok');
        (new Client())->send(Request::get('https://method.example.test/body')->withBody('payload'));
        $options = \FakeCurl::$requests[0]['options'];
        $assert(($options[\CURLOPT_CUSTOMREQUEST] ?? null) === 'GET');
        $assert(($options[\CURLOPT_POSTFIELDS] ?? null) === 'payload');
        $assert(!isset($options[\CURLOPT_HTTPGET]));
        $throws(static fn() => (new Client())->withCurlOption(\CURLOPT_URL, 'https://attacker.example.test/'), \InvalidArgumentException::class);
    });

    $test('Client strips credential-bearing cURL options on cross-origin redirects', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://auth-origin.example.test/start', 302, ['Location' => 'https://auth-target.example.test/end']);
        \FakeCurl::set('https://auth-target.example.test/end', 200, [], 'ok');
        $client = (new Client())->withCurlOption(\CURLOPT_USERPWD, 'alice:top-secret');
        $client->send(Request::get('https://auth-origin.example.test/start'));
        $assert(isset(\FakeCurl::$requests[0]['options'][\CURLOPT_USERPWD]));
        $assert(!isset(\FakeCurl::$requests[1]['options'][\CURLOPT_USERPWD]));
    });

    $test('Client rejects redirect URLs that introduce user credentials', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://safe.example.test/start', 302, ['Location' => 'https://alice:secret@evil.example.test/collect']);
        try {
            (new Client())->send(Request::get('https://safe.example.test/start'));
            throw new \RuntimeException('Credential-bearing redirect was accepted.');
        } catch (\RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'URL credentials'));
        }
        $assert(count(\FakeCurl::$requests) === 1);
    });

    $test('Client applies API key query authentication', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://api.example.test/data?api_key=secret', 200, [], 'ok');
        $client = (new Client())->withApiKey('api_key', 'secret', 'query');
        $client->send(Request::get('https://api.example.test/data'));
        $assert(\FakeCurl::$requests[0]['url'] === 'https://api.example.test/data?api_key=secret');
    });

    $test('Downloader rejects a 200 response with an incomplete Content-Length', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/truncated.bin';
        \FakeCurl::set('https://download.example.test/truncated.bin', 200, ['Content-Length' => '10'], 'short');
        $downloader = (new Downloader('https://download.example.test/truncated.bin', $path))->withRetries(1, 0);
        try {
            (new Client())->download($downloader);
            throw new \RuntimeException('Truncated download was accepted.');
        } catch (\RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'Content-Length'));
        }
        $assert(!file_exists($path));
        @unlink($path . '.part');
        @rmdir($directory);
    });

    $test('Downloader streams a normal response into a file', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        $path = $directory . '/file.bin';
        \FakeCurl::set('https://download.example.test/file.bin', 200, ['Content-Length' => '5'], 'hello');
        $downloader = (new Downloader('https://download.example.test/file.bin', $path))->withChunkSize(2);
        $client = new Client();
        $assert($client->download($downloader));
        $assert(file_get_contents($path) === 'hello');
        $assert(!file_exists($path . '.part'));
        @unlink($path);
        @rmdir($directory);
    });

    $test('Downloader progress uses the complete Content-Range total', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/progress.bin';
        file_put_contents($path . '.part', 'he');
        \FakeCurl::set('https://download.example.test/progress.bin', 206, ['Content-Range' => 'bytes 2-4/10'], 'llo');
        $progress = [];
        $downloader = (new Downloader('https://download.example.test/progress.bin', $path))
            ->withResume(true)->withProgress(static function (array $state) use (&$progress): void { $progress[] = $state; });
        try { (new Client())->download($downloader); } catch (\RuntimeException) {}
        $assert($progress !== [] && (int)$progress[0]['total'] === 10, 'Progress total did not use Content-Range total: ' . var_export($progress, true));
        @unlink($path . '.part');
        @unlink($path);
        @rmdir($directory);
    });

    $test('Downloader resumes only from a matching Content-Range offset', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/file.bin';
        file_put_contents($path . '.part', 'he');
        \FakeCurl::set('https://download.example.test/resume.bin', 206, ['Content-Range' => 'bytes 2-4/5', 'Content-Length' => '3'], 'llo');
        $downloader = (new Downloader('https://download.example.test/resume.bin', $path))->withResume(true);
        (new Client())->download($downloader);
        $assert(file_get_contents($path) === 'hello');
        $headers = \FakeCurl::$requests[0]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(in_array('Range: bytes=2-', $headers, true));
        @unlink($path);
        @rmdir($directory);
    });

    $test('Downloader replaces partial data when server ignores Range and returns 200', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/file.bin';
        file_put_contents($path . '.part', 'old-part');
        \FakeCurl::set('https://download.example.test/ignore-range.bin', 200, ['Content-Length' => '5'], 'world');
        $downloader = (new Downloader('https://download.example.test/ignore-range.bin', $path))->withResume(true);
        (new Client())->download($downloader);
        $assert(file_get_contents($path) === 'world');
        @unlink($path);
        @rmdir($directory);
    });

    $test('Downloader rejects a mismatched Content-Range and preserves partial data', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/file.bin';
        file_put_contents($path . '.part', 'he');
        \FakeCurl::set('https://download.example.test/bad-range.bin', 206, ['Content-Range' => 'bytes 3-4/5'], 'lo');
        $downloader = (new Downloader('https://download.example.test/bad-range.bin', $path))->withResume(true)->withRetries(1, 0);
        try {
            (new Client())->download($downloader);
            throw new \RuntimeException('Mismatched Content-Range was accepted.');
        } catch (\RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'Content-Range'));
        }
        $assert(file_get_contents($path . '.part') === 'he');
        @unlink($path . '.part');
        @rmdir($directory);
    });

    $test('Downloader save keeps incomplete partial responses in the temporary file', static function () use ($assert): void {
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/partial.bin';
        $downloader = new Downloader('https://download.example.test/partial.bin', $path);
        $response = new HttpResponse('abc', "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 0-2/9\r\n\r\n", 206);
        $assert($downloader->save($response));
        $assert(!file_exists($path) && file_get_contents($path . '.part') === 'abc');
        @unlink($path . '.part');
        @rmdir($directory);
    });

    $test('Downloader follows multiple partial Content-Range responses', static function () use ($assert): void {
        \FakeCurl::reset();
        $directory = sys_get_temp_dir() . '/ultra-request-' . uniqid();
        mkdir($directory, 0755, true);
        $path = $directory . '/file.bin';
        file_put_contents($path . '.part', 'he');
        \FakeCurl::setSequence('https://download.example.test/segmented.bin', [
            [206, ['Content-Range' => 'bytes 2-3/6', 'Content-Length' => '2'], 'll'],
            [206, ['Content-Range' => 'bytes 4-5/6', 'Content-Length' => '2'], 'ow'],
        ]);
        $downloader = (new Downloader('https://download.example.test/segmented.bin', $path))->withResume(true);
        (new Client())->download($downloader);
        $assert(file_get_contents($path) === 'hellow');
        $assert(count(\FakeCurl::$requests) === 2);
        $secondHeaders = \FakeCurl::$requests[1]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(in_array('Range: bytes=4-', $secondHeaders, true));
        $assert(in_array('Accept-Encoding: identity', $secondHeaders, true));
        @unlink($path);
        @rmdir($directory);
    });

    $test('Request Cookie header overrides same-name CookieJar entry without duplication', static function () use ($assert): void {
        \FakeCurl::reset();
        $jar = new CookieJar(null);
        $jar->setCookieForUrl('https://cookie-merge.example.test/', 'session', 'jar-value');
        \FakeCurl::set('https://cookie-merge.example.test/', 200, [], 'ok');
        $request = Request::get('https://cookie-merge.example.test/')->withHeader('Cookie', 'session=request-value; manual=1');
        (new Client())->withCookieJar($jar)->send($request);
        $headers = \FakeCurl::$requests[0]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $cookieLines = array_values(array_filter($headers, static fn(string $line): bool => stripos($line, 'Cookie:') === 0));
        $assert(count($cookieLines) === 1);
        $assert($cookieLines[0] === 'Cookie: manual=1; session=request-value' || $cookieLines[0] === 'Cookie: session=request-value; manual=1');
    });

    $test('Client stores host-only cookies and matches their default request path', static function () use ($assert): void {
        \FakeCurl::reset();
        $jar = new CookieJar(null);
        \FakeCurl::set('https://cookie.example.test/account/login', 200, ['Set-Cookie' => 'session=abc; HttpOnly'], 'ok');
        \FakeCurl::set('https://cookie.example.test/account/home', 200, [], 'ok');
        \FakeCurl::set('https://cookie.example.test/accounting/home', 200, [], 'ok');
        $client = (new Client())->withCookieJar($jar);
        $client->send(Request::get('https://cookie.example.test/account/login'));
        $client->send(Request::get('https://cookie.example.test/account/home'));
        $client->send(Request::get('https://cookie.example.test/accounting/home'));
        $secondHeaders = \FakeCurl::$requests[1]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $thirdHeaders = \FakeCurl::$requests[2]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(in_array('Cookie: session=abc', $secondHeaders, true));
        $assert(!in_array('Cookie: session=abc', $thirdHeaders, true));
    });

    $test('CookieJar persists cookies and reloads host-only metadata', static function () use ($assert): void {
        $directory = sys_get_temp_dir() . '/ultra-cookie-' . uniqid();
        mkdir($directory, 0700, true);
        $storage = $directory . '/cookies.json';
        $jar = new CookieJar($storage);
        $jar->setCookieForUrl('https://persist.example.test/account/login', 'sid', 'persisted', ['httpOnly' => true]);
        $loaded = new CookieJar($storage, true);
        $cookies = $loaded->match('https://persist.example.test/account/home');
        $assert(count($cookies) === 1 && $cookies[0]->getValue() === 'persisted');
        $assert(!$loaded->match('https://sub.persist.example.test/account/home'));
        @unlink($storage);
        @rmdir($directory);
    });

    $test('POST becomes GET after 302 while 307 preserves method and body', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://redirect.example.test/post302', 302, ['Location' => '/target302']);
        \FakeCurl::set('https://redirect.example.test/target302', 200, [], 'ok');
        $client = new Client();
        $client->send(Request::post('https://redirect.example.test/post302')->withBody('payload'));
        $second302 = \FakeCurl::$requests[1]['options'];
        $assert(isset($second302[\CURLOPT_HTTPGET]));
        $assert(!isset($second302[\CURLOPT_POSTFIELDS]));

        \FakeCurl::reset();
        \FakeCurl::set('https://redirect.example.test/post307', 307, ['Location' => '/target307']);
        \FakeCurl::set('https://redirect.example.test/target307', 200, [], 'ok');
        $client->send(Request::post('https://redirect.example.test/post307')->withBody('payload'));
        $second307 = \FakeCurl::$requests[1]['options'];
        $assert(($second307[\CURLOPT_POSTFIELDS] ?? null) === 'payload');
        $assert(isset($second307[\CURLOPT_POST]));
    });

    $test('Cross-origin 307 redirect removes custom Host and sensitive default headers', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://a.example.test/start', 307, ['Location' => 'https://b.example.test/next']);
        \FakeCurl::set('https://b.example.test/next', 200, [], 'ok');
        $client = (new Client())->withDefaultHeaders(['Host' => 'a.example.test', 'X-Private-Token' => 'secret']);
        $client->send(Request::get('https://a.example.test/start'));
        $headers = \FakeCurl::$requests[1]['options'][\CURLOPT_HTTPHEADER] ?? [];
        $assert(!in_array('Host: a.example.test', $headers, true));
        $assert(!in_array('X-Private-Token: secret', $headers, true));
    });

    $test('Client mutators preserve the same instance and UltraRequest facade is available', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://facade.example.test/', 200, [], 'ok');
        $client = new Client();
        $same = $client;
        $client->withTimeout(12)->withConnectTimeout(4)->withSSL(true)->withDefaultHeaders(['X-App' => 'test']);
        $assert($client === $same);
        $facade = new \Amirxd\UltraRequest\UltraRequest($client);
        $assert($facade->get('https://facade.example.test/')->getStatusCode() === 200);

        \FakeCurl::reset();
        \FakeCurl::set('https://facade.example.test/one', 200, [], 'one');
        \FakeCurl::set('https://facade.example.test/two', 200, [], 'two');
        $batch = $facade->sendMany([
            'one' => Request::get('https://facade.example.test/one'),
            'two' => Request::get('https://facade.example.test/two'),
        ], 2);
        $assert($batch['one'] instanceof HttpResponse);
        $assert($batch['two'] instanceof HttpResponse);
    });

    $test('Middleware executes before hooks in order and response hooks in reverse order', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/pipeline', 200, [], 'network');

        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $outer = new class($record) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private \Closure $record;

            public function __construct(callable $record)
            {
                $this->record = \Closure::fromCallable($record);
            }

            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                ($this->record)('outer.before');
                $context->setAttribute('trace', 'outer');
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                ($this->record)('outer.after');
                return $response;
            }
        };

        $inner = new class($record) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private \Closure $record;

            public function __construct(callable $record)
            {
                $this->record = \Closure::fromCallable($record);
            }

            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                ($this->record)('inner.before');
                $context->setAttribute('inner', true);
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                ($this->record)('inner.after');
                return $response;
            }
        };

        $client = (new Client())->addMiddleware($outer)->addMiddleware($inner);
        $response = $client->send(Request::get('https://middleware.example.test/pipeline'));

        $assert($response->getBody() === 'network');
        $assert($events === [
            'outer.before',
            'inner.before',
            'inner.after',
            'outer.after',
        ]);
    });

    $test('Middleware can modify an outgoing request before transport', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/modified', 200, [], 'ok');

        $middleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                $request = $context->getRequest();
                if ($request instanceof Request) {
                    $request->withHeader('X-Middleware', 'enabled');
                    $request->withQueryParams(['source' => 'middleware']);
                }
            }
        };

        $client = (new Client())->addMiddleware($middleware);
        $client->send(Request::get('https://middleware.example.test/modified'));

        $sent = \FakeCurl::$requests[0];
        $assert(str_contains($sent['url'], 'source=middleware'));
        $assert(in_array('X-Middleware: enabled', $sent['options'][\CURLOPT_HTTPHEADER] ?? [], true));
    });

    $test('Middleware short-circuits requests and still runs entered response hooks', static function () use ($assert): void {
        \FakeCurl::reset();

        $events = [];
        $middleware = new class($events) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private array $events;

            public function __construct(array &$events)
            {
                $this->events =& $events;
            }

            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                $this->events[] = 'before';
                $context->respond(new HttpResponse('cached-body', "HTTP/1.1 200 OK\r\nX-Cache: HIT\r\n\r\n", 200));
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                $this->events[] = 'after';
                return $response;
            }
        };

        $response = (new Client())->addMiddleware($middleware)->send(
            Request::get('https://cache.example.test/resource')
        );

        $assert($response->getBody() === 'cached-body');
        $assert($response->getHeader('X-Cache') === 'HIT');
        $assert(\FakeCurl::$requests === []);
        $assert($events === ['before', 'after']);
    });

    $test('Middleware error hooks can recover failed single requests', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::fail('https://middleware.example.test/fail', 7, 'Connection refused');

        $errorMiddleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function onError(
                \Amirxd\UltraRequest\Middleware\RequestContext $context,
                \Throwable $error
            ): HttpResponse|\Throwable {
                return new HttpResponse('fallback', "HTTP/1.1 200 OK\r\nX-Recovered: yes\r\n\r\n", 200);
            }
        };

        $response = (new Client())->addMiddleware($errorMiddleware)->send(
            Request::get('https://middleware.example.test/fail')
        );

        $assert($response instanceof HttpResponse);
        $assert($response->getBody() === 'fallback');
        $assert($response->getHeader('X-Recovered') === 'yes');
    });

    $test('Middleware before-hook exceptions are passed to error middleware', static function () use ($assert): void {
        \FakeCurl::reset();

        $middleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                throw new \RuntimeException('Preparation failed');
            }

            public function onError(
                \Amirxd\UltraRequest\Middleware\RequestContext $context,
                \Throwable $error
            ): HttpResponse|\Throwable {
                return new HttpResponse('prepared-fallback', "HTTP/1.1 200 OK\r\n\r\n", 200);
            }
        };

        $response = (new Client())->addMiddleware($middleware)->send(
            Request::get('https://middleware.example.test/not-requested')
        );

        $assert($response->getBody() === 'prepared-fallback');
        $assert(\FakeCurl::$requests === []);
    });

    $test('Middleware response hooks execute once after the final redirect response', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/redirect', 302, ['Location' => '/final']);
        \FakeCurl::set('https://middleware.example.test/final', 200, [], 'final-body');

        $afterCount = 0;
        $middleware = new class($afterCount) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private int $afterCount;

            public function __construct(int &$afterCount)
            {
                $this->afterCount =& $afterCount;
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                $this->afterCount++;
                return $response;
            }
        };

        $response = (new Client())->addMiddleware($middleware)->send(
            Request::get('https://middleware.example.test/redirect')
        );

        $assert($response->getBody() === 'final-body');
        $assert($afterCount === 1);
    });

    $test('Concurrent requests use the same middleware pipeline with isolated contexts', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/one', 200, [], 'one');
        \FakeCurl::set('https://middleware.example.test/two', 200, [], 'two');

        $observed = [];
        $middleware = new class($observed) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private array $observed;

            public function __construct(array &$observed)
            {
                $this->observed =& $observed;
            }

            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                $context->setAttribute('request-key', $context->getKey());
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                $this->observed[$context->getKey()] = $context->getAttribute('request-key');
                return $response;
            }
        };

        $client = (new Client())->addMiddleware($middleware);
        $results = $client->sendMany([
            'one' => Request::get('https://middleware.example.test/one'),
            'two' => Request::get('https://middleware.example.test/two'),
        ], 2);

        $assert($results['one'] instanceof HttpResponse);
        $assert($results['two'] instanceof HttpResponse);
        $assert($observed === ['one' => 'one', 'two' => 'two']);
    });

    $test('Concurrent request errors are passed through middleware and can be recovered', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::fail('https://middleware.example.test/batch-fail', 7, 'Connection refused');
        \FakeCurl::set('https://middleware.example.test/batch-ok', 200, [], 'ok');

        $recoveredKeys = [];
        $middleware = new class($recoveredKeys) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private array $recoveredKeys;

            public function __construct(array &$recoveredKeys)
            {
                $this->recoveredKeys =& $recoveredKeys;
            }

            public function onError(
                \Amirxd\UltraRequest\Middleware\RequestContext $context,
                \Throwable $error
            ): HttpResponse|\Throwable {
                $this->recoveredKeys[] = $context->getKey();
                return new HttpResponse('recovered-' . $context->getKey(), "HTTP/1.1 200 OK\r\n\r\n", 200);
            }
        };

        $results = (new Client())->addMiddleware($middleware)->sendMany([
            'failed' => Request::get('https://middleware.example.test/batch-fail'),
            'healthy' => Request::get('https://middleware.example.test/batch-ok'),
        ], 2);

        $assert($results['failed'] instanceof HttpResponse);
        $assert($results['failed']->getBody() === 'recovered-failed');
        $assert($results['healthy'] instanceof HttpResponse);
        $assert($results['healthy']->getBody() === 'ok');
        $assert($recoveredKeys === ['failed']);
    });

    $test('Middleware can transform responses and replace errors', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/transform', 200, [], 'original');

        $middleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                return new HttpResponse(
                    strtoupper((string)$response->getBody()),
                    $response->getRawHeaders(),
                    $response->getStatusCode(),
                    $response->getInfo()
                );
            }

            public function onError(
                \Amirxd\UltraRequest\Middleware\RequestContext $context,
                \Throwable $error
            ): HttpResponse|\Throwable {
                return new \RuntimeException('Middleware: ' . $error->getMessage(), 0, $error);
            }
        };

        $response = (new Client())->addMiddleware($middleware)->send(
            Request::get('https://middleware.example.test/transform')
        );

        $assert($response->getBody() === 'ORIGINAL');
    });

    $test('Concurrent middleware can transform each completed response', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/upper-one', 200, [], 'one');
        \FakeCurl::set('https://middleware.example.test/upper-two', 200, [], 'two');

        $middleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                return new HttpResponse(
                    strtoupper((string)$response->getBody()),
                    $response->getRawHeaders(),
                    $response->getStatusCode(),
                    $response->getInfo()
                );
            }
        };

        $results = (new Client())->addMiddleware($middleware)->sendMany([
            'one' => Request::get('https://middleware.example.test/upper-one'),
            'two' => Request::get('https://middleware.example.test/upper-two'),
        ], 2);

        $assert($results['one'] instanceof HttpResponse);
        $assert($results['two'] instanceof HttpResponse);
        $assert($results['one']->getBody() === 'ONE');
        $assert($results['two']->getBody() === 'TWO');
    });

    $test('Concurrent middleware supports short-circuit responses without creating handles', static function () use ($assert): void {
        \FakeCurl::reset();

        $middleware = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            public function before(\Amirxd\UltraRequest\Middleware\RequestContext $context): void
            {
                if ($context->getKey() === 'cached') {
                    $context->respond(new HttpResponse('from-cache', "HTTP/1.1 200 OK\r\n\r\n", 200));
                }
            }
        };

        $results = (new Client())->addMiddleware($middleware)->sendMany([
            'cached' => Request::get('https://middleware.example.test/cached'),
            'network' => Request::get('https://middleware.example.test/network'),
        ], 2);

        $assert($results['cached'] instanceof HttpResponse);
        $assert($results['cached']->getBody() === 'from-cache');
        $assert($results['network'] instanceof HttpResponse);
        $assert(count(\FakeCurl::$requests) === 1);
        $assert(\FakeCurl::$requests[0]['url'] === 'https://middleware.example.test/network');
    });

    $test('Middleware after-hook failures enter the error pipeline', static function () use ($assert): void {
        \FakeCurl::reset();
        \FakeCurl::set('https://middleware.example.test/after-failure', 200, [], 'ok');

        $events = [];
        $outer = new class($events) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private array $events;

            public function __construct(array &$events)
            {
                $this->events =& $events;
            }

            public function onError(\Amirxd\UltraRequest\Middleware\RequestContext $context, \Throwable $error): HttpResponse|\Throwable
            {
                $this->events[] = 'outer.error';
                return $error;
            }
        };
        $inner = new class($events) extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {
            private array $events;

            public function __construct(array &$events)
            {
                $this->events =& $events;
            }

            public function after(\Amirxd\UltraRequest\Middleware\RequestContext $context, HttpResponse $response): HttpResponse
            {
                $this->events[] = 'inner.after';
                throw new \RuntimeException('Response inspection failed');
            }

            public function onError(\Amirxd\UltraRequest\Middleware\RequestContext $context, \Throwable $error): HttpResponse|\Throwable
            {
                $this->events[] = 'inner.error';
                return $error;
            }
        };

        $throws = static function (callable $callback, string $class, string $message) use ($assert): void {
            try {
                $callback();
            } catch (\Throwable $error) {
                $assert($error instanceof $class);
                $assert(str_contains($error->getMessage(), $message));
                return;
            }
            throw new \RuntimeException('Expected middleware response-hook failure was not thrown.');
        };

        $client = (new Client())->addMiddleware($outer)->addMiddleware($inner);
        $throws(
            static fn() => $client->send(Request::get('https://middleware.example.test/after-failure')),
            \RuntimeException::class,
            'Response inspection failed'
        );
        $assert($events === ['inner.after', 'inner.error', 'outer.error']);
    });

    $test('Middleware attributes distinguish a stored null value from a missing key', static function () use ($assert): void {
        $context = new \Amirxd\UltraRequest\Middleware\RequestContext(
            Request::get('https://middleware.example.test/context'),
            'context-key'
        );
        $context->setAttribute('nullable', null);

        $assert($context->hasAttribute('nullable'));
        $assert($context->getAttribute('nullable', 'fallback') === null);
        $assert($context->getAttribute('missing', 'fallback') === 'fallback');
        $assert($context->getKey() === 'context-key');
    });

    $test('Middleware can be prepended, removed, cleared, and added through the facade', static function () use ($assert): void {
        $client = new Client();
        $first = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {};
        $second = new class extends \Amirxd\UltraRequest\Middleware\AbstractMiddleware {};

        $client->addMiddleware($first)->prependMiddleware($second);
        $assert($client->getMiddlewares() === [$second, $first]);
        $client->removeMiddleware($second);
        $assert($client->getMiddlewares() === [$first]);
        $client->removeMiddleware(get_class($first));
        $assert($client->getMiddlewares() === []);

        $facade = new \Amirxd\UltraRequest\UltraRequest($client);
        $facade->addMiddleware($second)->clearMiddlewares();
        $assert($client->getMiddlewares() === []);
    });

    echo "\nTests: {$passed} passed, {$failed} failed.\n";
    exit($failed === 0 ? 0 : 1);
}
