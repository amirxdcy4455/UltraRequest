# PHP Ultra Request

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-yellow?style=flat-square)](LICENSE)
[![Packagist](https://img.shields.io/badge/Packagist-amirxd%2Fphp--ultra--request-blue?style=flat-square)](https://packagist.org/packages/amirxd/php-ultra-request)

A powerful, feature-rich HTTP client library for PHP with full cURL support, cookie management, proxy handling, authentication, and advanced file downloading capabilities.

---

## ✨ Features

- **🔌 Full cURL Integration** - Leverages PHP's cURL extension for maximum performance
- **🍪 Cookie Management** - Full cookie jar with persistence, parsing, and domain/path matching
- **🌐 Proxy Support** - HTTP, HTTPS, SOCKS4, SOCKS5 proxies with authentication
- **🔐 Authentication** - Basic, Bearer Token, and API Key authentication methods
- **📥 Advanced Downloader** - Resume support, progress tracking, console progress bar, retry mechanism
- **🔄 Manual Redirect Handling** - RFC 7231 compliant, preserves method semantics (301/302/307/308)
- **🧹 Clean Architecture** - PSR-4 compliant, fully object-oriented with interfaces
- **⚡ Fluent Interface** - Method chaining for elegant API usage
- **🧪 JSON Support** - Automatic JSON encoding/decoding with type safety
- **🔒 SSL Verification** - Optional SSL verification for development/secure environments

---

## 📋 Requirements

- PHP 8.0 or higher
- cURL extension (`ext-curl`)
- JSON extension (`ext-json`)

---

## 🚀 Installation

### Via Composer

```bash
composer require amirxd/php-ultra-request:dev-main
```

### Manual Installation

```bash
git clone https://github.com/amirxd/php-ultra-request.git
cd php-ultra-request
composer install
```

---

## 📖 Quick Start

### Basic GET Request

```php
<?php

require_once 'vendor/autoload.php';

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();

$response = $http->get('https://api.github.com/users/amirxd');

if ($response->isSuccessful()) {
    $data = $response->getJson();
    echo "User: " . ($data['name'] ?? 'N/A') . "\n";
    echo "Bio: " . ($data['bio'] ?? 'N/A') . "\n";
}
```

### POST Request with JSON

```php
<?php

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();

$data = [
    'title' => 'New Post',
    'body' => 'Lorem ipsum dolor sit amet',
    'userId' => 1
];

$response = $http->post(
    'https://jsonplaceholder.typicode.com/posts',
    json_encode($data),
    ['Content-Type' => 'application/json']
);

if ($response->isSuccessful()) {
    $result = $response->getJson();
    echo "Created post ID: " . $result['id'] . "\n";
}
```

### Using the Fluent Request Builder

```php
<?php

use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Client\Client;

$client = new Client();
$request = Request::post('https://api.example.com/users')
    ->withHeader('X-API-Key', 'your-api-key-here')
    ->withJson(['name' => 'John Doe', 'email' => 'john@example.com'])
    ->withQueryParams(['source' => 'web']);

$response = $client->send($request);
```

---

## 🔐 Authentication

### Basic Authentication

```php
<?php

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();
$client = $http->getClient()->withBasicAuth('username', 'password');

$response = $client->send(
    Request::get('https://api.example.com/protected')
);
```

### Bearer Token (OAuth2)

```php
<?php

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();
$client = $http->getClient()->withBearerAuth('your-token-here');

$response = $client->send(
    Request::get('https://api.example.com/user')
);
```

### API Key Authentication

```php
<?php

use Amirxd\UltraRequest\Auth\AuthManager;
use Amirxd\UltraRequest\Client\Client;

$auth = new AuthManager();
$auth->apiKey('X-API-Key', 'your-api-key', 'header');

$client = (new Client())->withAuth(function($manager) {
    $manager->apiKey('X-API-Key', 'your-api-key', 'header');
});

$response = $client->send(
    Request::get('https://api.example.com/protected')
);
```

### Using AuthManager with Fluent API

```php
<?php

use Amirxd\UltraRequest\Client\Client;

$client = new Client();
$client = $client->withAuth(function($auth) {
    $auth->basic('username', 'password');
    // OR
    $auth->bearer('your-token-here');
    // OR
    $auth->apiKey('X-API-Key', 'your-key', 'header');
});
```

---

## 🍪 Cookie Management

### Basic Cookie Usage

```php
<?php

use Amirxd\UltraRequest\Cookie\CookieJar;
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$cookieJar = new CookieJar('cookies.json', true); // Auto-load from file
$client = (new Client())->withCookieJar($cookieJar);

// Cookies will be automatically saved and loaded
$response = $client->send(Request::get('https://example.com'));

// Manually set a cookie
$cookieJar->setCookie('session_id', 'abc123', [
    'domain' => '.example.com',
    'path' => '/',
    'expires' => time() + 3600,
    'secure' => true,
    'httpOnly' => true
]);

// Get all cookies as header string
$cookieHeader = $cookieJar->toHeader();
```

### Cookie Persistence

```php
<?php

// Save cookies to file
$cookieJar = new CookieJar('cookies.json');
$cookieJar->setCookie('user', 'john_doe');

// Load existing cookies
$cookieJar = new CookieJar('cookies.json', true);
echo $cookieJar->getCookie('user')->getValue(); // john_doe
```

---

## 🌐 Proxy Support

### HTTP/HTTPS Proxy

```php
<?php

use Amirxd\UltraRequest\Proxy\Proxy;
use Amirxd\UltraRequest\Client\Client;

$proxy = new Proxy('http://proxy.example.com:8080');

// With authentication
$proxy = (new Proxy('http://proxy.example.com:8080'))
    ->withAuth('username', 'password');

$client = (new Client())->setProxy($proxy);
```

### SOCKS Proxy

```php
<?php

use Amirxd\UltraRequest\Proxy\Proxy;

// SOCKS5 proxy
$proxy = new Proxy('socks5://socks.example.com:1080');

// SOCKS4 proxy
$proxy = new Proxy('socks4://socks.example.com:1080');

$client = (new Client())->setProxy($proxy);
```

### Proxy String Formats

```php
// All these formats are supported:
$proxy = new Proxy('http://proxy.example.com:8080');
$proxy = new Proxy('proxy.example.com:8080'); // Defaults to HTTP
$proxy = new Proxy('https://proxy.example.com:443');
$proxy = new Proxy('socks5://user:pass@socks.example.com:1080');
```

---

## 📥 Advanced File Downloader

### Basic Download

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

$downloader = new Downloader(
    'https://example.com/file.zip',
    '/path/to/save/file.zip'
);

$client = new Client();
$client->download($downloader);
```

### Download with Progress Bar (Console)

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

$downloader = new Downloader(
    'https://example.com/large-file.zip',
    './downloads/file.zip'
);

// Enable console progress bar
$downloader = $downloader->withConsoleProgress(50);

$client = new Client();
$client->download($downloader);

// Output: [████████████████████░░░░░░░] 75.0% | 15.3 MB / 20.4 MB | 2.1 MB/s | ETA: 00:24
```

### Download with Custom Progress Callback

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

$downloader = new Downloader(
    'https://example.com/file.zip',
    './downloads/file.zip'
);

$downloader = $downloader->withProgress(function($progress) {
    echo sprintf(
        "Downloaded: %s / %s (%.1f%%) | Speed: %s | ETA: %s\n",
        $progress['downloaded_formatted'],
        $progress['total_formatted'],
        $progress['percent'],
        $progress['speed_formatted'],
        $progress['eta_formatted']
    );
});

$client = new Client();
$client->download($downloader);
```

### Resume Download

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

$downloader = new Downloader(
    'https://example.com/large-file.zip',
    './downloads/file.zip'
);

// Enable resume support
$downloader = $downloader->withResume(true);

$client = new Client();
$client->download($downloader); // Will resume if file exists
```

### Download with Retry Mechanism

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

$downloader = new Downloader(
    'https://example.com/file.zip',
    './downloads/file.zip'
);

// Retry up to 3 times with 2-second delay between attempts
$downloader = $downloader->withRetries(3, 2);

$client = new Client();
$client->download($downloader);
```

### Complete Download Example

```php
<?php

use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Client\Client;

try {
    $downloader = new Downloader(
        'https://example.com/large-file.zip',
        './downloads/large-file.zip'
    );
    
    $downloader = $downloader
        ->withResume(true)
        ->withConsoleProgress(50)
        ->withRetries(3, 2)
        ->withOverwrite(false) // Don't overwrite if exists (unless resuming)
        ->withHeader('User-Agent', 'PHP Ultra Request')
        ->withChunkSize(1024 * 1024 * 2); // 2MB chunks
    
    $client = new Client();
    $success = $client->download($downloader);
    
    if ($success) {
        echo "\n✅ Download completed!\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Download failed: " . $e->getMessage() . "\n";
}
```

---

## ⚙️ Advanced Client Configuration

### Custom Headers

```php
<?php

use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = new Client();
$client = $client->withAuth(function($auth) {
    $auth->bearer('your-token');
});

$request = Request::get('https://api.example.com/users')
    ->withHeader('X-Custom-Header', 'custom-value')
    ->withHeader('Accept', 'application/json');

$response = $client->send($request);
```

### Timeout Configuration

```php
<?php

use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = (new Client())
    ->withTimeout(60)           // Total timeout: 60 seconds
    ->withConnectTimeout(10);   // Connection timeout: 10 seconds

$response = $client->send(Request::get('https://slow-api.example.com'));
```

### SSL Verification Control

```php
<?php

use Amirxd\UltraRequest\Client\Client;

// Enable SSL verification (default, recommended for production)
$client = (new Client())->withSSL(true);

// Disable SSL verification (useful for development)
$client = (new Client())->withSSL(false);
```

### Custom cURL Options

```php
<?php

use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = (new Client())
    ->withCurlOption(CURLOPT_USERAGENT, 'MyCustomBot/1.0')
    ->withCurlOption(CURLOPT_MAXREDIRS, 5)
    ->withCurlOption(CURLOPT_REFERER, 'https://google.com');

$response = $client->send(Request::get('https://example.com'));
```

---

## 🔍 Response Handling

### Response Methods

```php
<?php

use Amirxd\UltraRequest\UltraRequest;

$http = new UltraRequest();
$response = $http->get('https://api.example.com/data');

// Get status code
$statusCode = $response->getStatusCode(); // 200

// Get response body
$body = $response->getBody(); // Raw string

// Get JSON decoded data
$data = $response->getJson(true); // Associative array
$data = $response->getJson(false); // Object

// Check response status
if ($response->isSuccessful()) {
    // 2xx responses
} elseif ($response->isRedirect()) {
    // 3xx responses
} elseif ($response->isClientError()) {
    // 4xx responses
} elseif ($response->isServerError()) {
    // 5xx responses
}

// Get specific header
$contentType = $response->getHeader('Content-Type');
$allHeaders = $response->getHeaders();

// Get cURL info
$info = $response->getInfo(); // URL, total_time, etc.
```

---

## 🧪 Testing

### Run Tests

```bash
# Download a test file
php tests/DownloadTest.php

# Run the basic test script
php tests/UltraRequestTest.php
```

### Example Test Script

```php
<?php
// tests/UltraRequestTest.php

require_once 'vendor/autoload.php';

use Amirxd\UltraRequest\UltraRequest;

echo "🧪 Testing UltraRequest...\n\n";

try {
    $http = new UltraRequest();
    
    // Test GET
    echo "📡 Testing GET request...\n";
    $response = $http->get('https://httpbin.org/get');
    echo "✅ Status: " . $response->getStatusCode() . "\n";
    
    // Test POST
    echo "\n📡 Testing POST request...\n";
    $response = $http->post('https://httpbin.org/post', 
        json_encode(['test' => 'success']),
        ['Content-Type' => 'application/json']
    );
    echo "✅ Status: " . $response->getStatusCode() . "\n";
    
    echo "\n✨ All tests passed!\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
```

---

## 📁 Directory Structure

```
php-ultra-request/
├── src/
│   ├── Auth/
│   │   ├── ApiKeyAuth.php
│   │   ├── AuthInterface.php
│   │   ├── AuthManager.php
│   │   ├── BasicAuth.php
│   │   └── BearerAuth.php
│   ├── Client/
│   │   └── Client.php
│   ├── Cookie/
│   │   ├── Cookie.php
│   │   ├── CookieInterface.php
│   │   ├── CookieJar.php
│   │   └── CookieJarInterface.php
│   ├── File/
│   │   └── Downloader.php
│   ├── Proxy/
│   │   ├── Proxy.php
│   │   └── ProxyInterface.php
│   ├── Request/
│   │   ├── Request.php
│   │   └── RequestInterface.php
│   ├── Response/
│   │   ├── HttpResponse.php
│   │   └── ResponseInterface.php
│   └── UltraRequest.php
├── tests/
│   └── UltraRequestTest.php
├── examples/
│   ├── basic-usage.php
│   ├── download-example.php
│   └── cookie-example.php
├── composer.json
├── LICENSE
├── README.md
└── .gitignore
```

---

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Coding Standards

- Follow PSR-12 coding standards
- Add tests for new features
- Update documentation accordingly
- Ensure PHP 8.0+ compatibility

---

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## 🙏 Acknowledgments

- Built with PHP's powerful cURL extension
- Inspired by Guzzle HTTP Client
- RFC 7231 compliant for HTTP/1.1

---

## 📫 Contact

- **Author**: Amirxd
- **Email**: amirxd4488.pm@example.com
- **GitHub**: [github.com/amirxd](https://github.com/amirxd)

---

## 🌟 Star the Project

If you find this library useful, please consider giving it a ⭐ on GitHub!

---

**Made with ❤️ by Amirxd**
