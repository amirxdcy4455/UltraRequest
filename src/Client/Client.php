<?php

namespace Amirxd\UltraRequest\Client;

use Amirxd\UltraRequest\Request\RequestInterface;
use Amirxd\UltraRequest\Response\HttpResponse;
use Amirxd\UltraRequest\Cookie\CookieJarInterface;
use Amirxd\UltraRequest\Proxy\ProxyInterface;
use Amirxd\UltraRequest\Auth\AuthManager;
use Amirxd\UltraRequest\File\Downloader;

class Client {
    private ?CookieJarInterface $cookieJar = null;
    private ?ProxyInterface $proxy = null;
    private array $defaultHeaders = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $verifySSL = true;
    private ?AuthManager $auth = null;
    private array $curlOptions = [];
    
    // ردیابی ریدایرکت برای اصلاح متد
    private int $redirectCount = 0;
    private array $redirectHistory = [];
    
    public function __construct() {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('cURL extension is required for UltraRequest');
        }
    }
    
    public function withAuth(callable $callback): self {
        $manager = new AuthManager();
        $callback($manager);
        $clone = clone $this;
        $clone->auth = $manager;
        return $clone;
    }

    public function withSSL(bool $verify = true): self {
        $clone = clone $this;
        $clone->verifySSL = $verify;
        return $clone;
    }
    
    public function withBasicAuth(string $username, string $password): self {
        $clone = clone $this;
        $clone->auth = (new AuthManager())->basic($username, $password);
        return $clone;
    }
    
    public function withBearerAuth(string $token): self {
        $clone = clone $this;
        $clone->auth = (new AuthManager())->bearer($token);
        return $clone;
    }
    
    public function withTimeout(int $seconds): self {
        $clone = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }
    
    public function withConnectTimeout(int $seconds): self {
        $clone = clone $this;
        $clone->connectTimeout = $seconds;
        return $clone;
    }

    public function setProxy(ProxyInterface $proxy): self {
        $clone = clone $this;
        $clone->proxy = $proxy;
        return $clone;
    }
    
    public function withCookieJar(CookieJarInterface $cookieJar): self {
        $clone = clone $this;
        $clone->cookieJar = $cookieJar;
        return $clone;
    }
    
    public function withCurlOption(int $option, $value): self {
        $clone = clone $this;
        $clone->curlOptions[$option] = $value;
        return $clone;
    }

    public function download(Downloader $downloader): bool {
        if (!$downloader->shouldDownload()) {
            throw new \RuntimeException("File already exists: " . $downloader->getSavePath());
        }
        
        $maxRetries = $downloader->getMaxRetries();
        $attempt = 0;
        $ch = null;
        
        do {
            try {
                $request = $downloader->getRequest();
                
                $ch = curl_init();
                
                if ($ch === false) {
                    throw new \RuntimeException("Failed to initialize cURL");
                }
                
                $options = $this->buildBaseOptions($request->getUrl(), $request);
                $options = $this->addHeaders($options, $request);
                $options = $this->addCookies($options, $request);
                $options = $this->addProxy($options);
                $options = $this->addCustomCurlOptions($options);
                
                $options[CURLOPT_RETURNTRANSFER] = true;
                $options[CURLOPT_HEADER] = true;
                
                if ($downloader->canResume()) {
                    $options[CURLOPT_RESUME_FROM] = $downloader->getExistingSize();
                }
                
                $options[CURLOPT_NOPROGRESS] = false;
                $options[CURLOPT_PROGRESSFUNCTION] = function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($downloader) {
                    $downloader->callProgress($downloaded, $downloadSize);
                };
                
                $this->applyCurlOptions($ch, $options);
                
                $response = curl_exec($ch);
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                $info = curl_getinfo($ch);
                
                if ($response === false) {
                    throw new \Exception("cURL Error ($errno): " . $error);
                }
                
                $httpResponse = $this->parseResponse($response, $info, $request);
                
                if ($downloader->save($httpResponse)) {
                    return true;
                }
                
                throw new \Exception("Failed to save file");
                
            } catch (\Exception $e) {
                $attempt++;
                // بستن cURL در صورت وجود
                if ($ch !== null && is_resource($ch)) {
                    curl_close($ch);
                    $ch = null;
                }
                
                if ($maxRetries !== null && $attempt >= $maxRetries) {
                    throw new \Exception("Download failed after $maxRetries attempts: " . $e->getMessage());
                }
                if ($maxRetries !== null) {
                    sleep($downloader->getRetryDelay());
                }
            }
            
        } while ($maxRetries !== null && $attempt < $maxRetries);
        
        return false;
    }
    
    public function send(RequestInterface $request): HttpResponse {
        $ch = curl_init();
        
        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize cURL");
        }
        
        // ریست کردن ریدایرکت ها
        $this->redirectCount = 0;
        $this->redirectHistory = [];
        
        $url = $request->getUrl();
        
        $options = $this->buildBaseOptions($url, $request);
        $options = $this->addHeaders($options, $request);
        $options = $this->addCookies($options, $request);
        $options = $this->addProxy($options);
        $options = $this->addCustomCurlOptions($options);
        
        // غیرفعال کردن follow location خودکار cURL برای کنترل دستی ریدایرکت
        $followRedirects = $request->followsRedirects();
        if ($followRedirects) {
            $options[CURLOPT_FOLLOWLOCATION] = false;
        }
        $options[CURLOPT_UNRESTRICTED_AUTH] = $followRedirects;
        
        $this->applyCurlOptions($ch, $options);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        
        if ($response === false) {
            curl_close($ch);
            throw new \Exception("cURL Error ($errno): " . $error);
        }
        
        $httpResponse = $this->parseResponse($response, $info, $request);
        
        // مدیریت دستی ریدایرکت‌ها
        if ($followRedirects && $this->shouldRedirect($httpResponse)) {
            $maxRedirects = $request->getMaxRedirects() ?? 10;
            
            if ($this->redirectCount >= $maxRedirects) {
                curl_close($ch);
                throw new \Exception("Maximum redirects ($maxRedirects) exceeded");
            }
            
            $location = $httpResponse->getHeader('Location');
            if ($location) {
                $newRequest = $this->buildRedirectRequest($request, $location, $httpResponse);
                curl_close($ch);
                return $this->send($newRequest);
            }
        }
        
        curl_close($ch);
        return $httpResponse;
    }
    
    private function shouldRedirect(HttpResponse $response): bool {
        $statusCode = $response->getStatusCode();
        return in_array($statusCode, [301, 302, 303, 307, 308]);
    }
    
    private function buildRedirectRequest(RequestInterface $originalRequest, string $location, HttpResponse $response): RequestInterface {
        $this->redirectCount++;
        $this->redirectHistory[] = $originalRequest->getUrl();
        
        // حل کردن URL نسبی
        if (!preg_match('/^https?:\/\//i', $location)) {
            $baseUrl = $originalRequest->getUrl();
            $location = $this->resolveRelativeUrl($baseUrl, $location);
        }
        
        $statusCode = $response->getStatusCode();
        $originalMethod = $originalRequest->getMethod();
        
        // طبق RFC 7231، ریدایرکت 301/302 باید متد را به GET تغییر دهد مگر اینکه 307/308 باشد
        if (in_array($statusCode, [301, 302, 303])) {
            $newMethod = 'GET';
            $newBody = null;
        } else {
            $newMethod = $originalMethod;
            $newBody = $originalRequest->getBody();
        }
        
        // ساخت ریسuest جدید با متد مناسب
        $class = get_class($originalRequest);
        $newRequest = new $class($newMethod, $location);
        
        // کپی کردن هدرها (به جز هدرهای مربوط به body)
        foreach ($originalRequest->getHeaders() as $name => $value) {
            if (!in_array(strtolower($name), ['content-length', 'content-type'])) {
                $newRequest = $newRequest->withHeader($name, $value);
            }
        }
        
        if ($newBody !== null) {
            $newRequest = $newRequest->withBody($newBody);
        }
        
        // کپی کردن تنظیمات دیگر
        $reflection = new \ReflectionClass($originalRequest);
        if ($reflection->hasProperty('followRedirects')) {
            $prop = $reflection->getProperty('followRedirects');
            $prop->setAccessible(true);
            $prop->setValue($newRequest, $prop->getValue($originalRequest));
        }
        
        return $newRequest;
    }
    
    private function resolveRelativeUrl(string $baseUrl, string $relativeUrl): string {
        $baseParts = parse_url($baseUrl);
        
        if (str_starts_with($relativeUrl, '/')) {
            // absolute path
            $scheme = $baseParts['scheme'] ?? 'http';
            $host = $baseParts['host'] ?? '';
            $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
            return $scheme . '://' . $host . $port . $relativeUrl;
        }
        
        // relative path
        $path = $baseParts['path'] ?? '';
        $path = dirname($path);
        if ($path !== '/' && !str_ends_with($path, '/')) {
            $path .= '/';
        }
        $scheme = $baseParts['scheme'] ?? 'http';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        
        return $scheme . '://' . $host . $port . $path . $relativeUrl;
    }
    
    private function buildBaseOptions(string $url, RequestInterface $request): array {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_ENCODING => '',
        ];
        
        $method = $request->getMethod();
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        
        $body = $request->getBody();
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        
        return $options;
    }
    
    private function addHeaders(array $options, RequestInterface $request): array {
        $headers = [];
        
        foreach ($this->defaultHeaders as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        if ($this->auth) {
            foreach ($this->auth->getHeaders() as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
        }
        
        foreach ($request->getHeaders() as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        if (!empty($headers)) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        
        return $options;
    }
    
    private function addCookies(array $options, RequestInterface $request): array {
        if ($this->cookieJar) {
            $cookies = $this->cookieJar->match($request->getUrl());
            if (!empty($cookies)) {
                $cookieStrings = array_map(function($c) {
                    return $c->getName() . '=' . $c->getValue();
                }, $cookies);
                $options[CURLOPT_COOKIE] = implode('; ', $cookieStrings);
            }
            $options[CURLOPT_COOKIEFILE] = '';
        }
        
        return $options;
    }
    
    private function addProxy(array $options): array {
        if ($this->proxy) {
            $proxyOptions = $this->proxy->getCurlOptions();
            foreach ($proxyOptions as $key => $value) {
                $options[$key] = $value;
            }
        }
        
        return $options;
    }
    
    private function addCustomCurlOptions(array $options): array {
        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }
        
        return $options;
    }
    
    private function applyCurlOptions($ch, array $options): void {
        foreach ($options as $option => $value) {
            if (is_int($option) && $option > 0) {
                curl_setopt($ch, $option, $value);
            }
        }
    }
    
    private function parseResponse(string $response, array $info, RequestInterface $request): HttpResponse {
        $headerSize = $info['header_size'];
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        if ($this->cookieJar) {
            $this->saveCookiesFromResponse($rawHeaders, $request->getUrl());
        }
        
        return new HttpResponse($body, $rawHeaders, $info['http_code'], $info);
    }
    
    private function saveCookiesFromResponse(string $rawHeaders, string $url): void {
        $domain = parse_url($url, PHP_URL_HOST);
        
        preg_match_all('/^Set-Cookie:\s*(.*)$/mi', $rawHeaders, $matches);
        
        foreach ($matches[1] as $cookieString) {
            $this->cookieJar->fromString($cookieString, $domain);
        }
    }
}