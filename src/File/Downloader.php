<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\File;

use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Response\HttpResponse;

class Downloader {
    private string $url;
    private string $savePath;
    private string $filename;
    private bool $resume = false;
    private $progressCallback = null;
    private int $chunkSize = 1024 * 1024;
    private array $headers = [];
    private bool $overwrite = false;
    private ?int $maxRetries = 3;
    private int $retryDelay = 1;
    private bool $consoleMode = false;
    private int $barWidth = 50;
    private int $lastPercent = -1;
    private int $lastBytes = 0;
    private float $lastTime = 0;
    
    // ذخیره سرعت‌های قبلی برای میانگین متحرک
    private array $speedHistory = [];
    private int $maxSpeedHistory = 5;
    
    public function __construct(string $url, ?string $savePath = null) {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Downloader URL must be an absolute HTTP or HTTPS URL.');
        }
        $this->url = $url;
        
        if ($savePath !== null && $savePath === '') throw new \InvalidArgumentException('Save path cannot be empty.');
        if ($savePath === null) {
            $this->filename = basename(parse_url($url, PHP_URL_PATH)) ?: 'download';
            $this->savePath = getcwd() . DIRECTORY_SEPARATOR . $this->filename;
        } else {
            $this->savePath = $savePath;
            $this->filename = basename($savePath);
        }
        
        $this->consoleMode = false;
    }
    
    public function withResume(bool $resume = true): self {
        $this->resume = $resume;
        return $this;
    }
    
    public function withProgress(callable $callback): self {
        $this->progressCallback = $callback;
        return $this;
    }
    
    public function withConsoleProgress(int $barWidth = 50): self {
        $this->consoleMode = true;
        $this->barWidth = max(5, $barWidth);
        return $this;
    }
    
    public function withChunkSize(int $bytes): self {
        if ($bytes < 1) throw new \InvalidArgumentException("Chunk size must be positive.");
        $this->chunkSize = $bytes;
        return $this;
    }
    
    public function withHeader(string $name, string $value): self {
        if ($name === "" || preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $name) || preg_match('/[\r\n]/', $value)) throw new \InvalidArgumentException("Invalid header.");
        foreach (array_keys($this->headers) as $existing) if (strcasecmp((string)$existing, $name) === 0) unset($this->headers[$existing]);
        $this->headers[$name] = $value;
        return $this;
    }
    
    public function withHeaders(array $headers): self {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) throw new \InvalidArgumentException('Headers must be an associative array of string values.');
            $this->withHeader($name, (string)$value);
        }
        return $this;
    }
    
    public function withOverwrite(bool $overwrite = true): self {
        $this->overwrite = $overwrite;
        return $this;
    }
    
    public function withRetries(int $maxRetries, int $delaySeconds = 1): self {
        if ($maxRetries < 1 || $delaySeconds < 0) throw new \InvalidArgumentException("Retries must be positive and delay cannot be negative.");
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $delaySeconds;
        return $this;
    }
    
    public function getUrl(): string {
        return $this->url;
    }
    
    public function getSavePath(): string {
        return $this->savePath;
    }
    
    public function getFilename(): string {
        return $this->filename;
    }
    
    public function canResume(): bool {
        return $this->resume && is_file($this->getPartPath());
    }

    public function getPartPath(): string {
        return $this->savePath . '.part';
    }

    public function getExistingSize(): int {
        $path = $this->getPartPath();
        return $this->canResume() && is_file($path) ? (int)filesize($path) : 0;
    }

    public function getChunkSize(): int {
        return $this->chunkSize;
    }

    public function getRequest(): Request {
        $request = Request::get($this->url);
        $request->withHeaders($this->headers);
        $request->withHeader('Accept-Encoding', 'identity');
        if ($this->canResume()) $request->withHeader('Range', 'bytes=' . $this->getExistingSize() . '-');
        return $request;
    }

    public function shouldDownload(): bool {
        return $this->overwrite || !file_exists($this->savePath);
    }

    public function save(HttpResponse $response): bool {
        if (!in_array($response->getStatusCode(), [200, 206], true) || $response->getBody() === null) return false;
        if (!$this->shouldDownload()) return false;
        $directory = dirname($this->savePath);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) return false;
        $offset = $this->getExistingSize();
        $contentRange = null;
        if ($response->getStatusCode() === 200) {
            $contentLength = $response->getHeader('Content-Length');
            if ($contentLength !== null && ctype_digit($contentLength) && strlen($response->getBody()) !== (int)$contentLength) return false;
        }
        if ($response->getStatusCode() === 206) {
            $contentRange = $this->getContentRange($response);
            if ($contentRange === null || $contentRange['start'] !== $offset) return false;
            $bodyLength = strlen($response->getBody());
            if ($bodyLength !== ($contentRange['end'] - $contentRange['start'] + 1)) return false;
            if ($contentRange['total'] !== null && $contentRange['end'] >= $contentRange['total']) return false;
        } else {
            $offset = 0;
        }
        $handle = fopen($this->getPartPath(), $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) return false;
        try {
            $body = $response->getBody();
            $written = 0;
            $length = strlen($body);
            while ($written < $length) {
                $count = fwrite($handle, substr($body, $written, $this->chunkSize));
                if ($count === false || $count === 0) return false;
                $written += $count;
            }
        } finally {
            fclose($handle);
        }
        if ($contentRange !== null && ($contentRange['total'] === null || (int)filesize($this->getPartPath()) < $contentRange['total'])) {
            return true;
        }
        try {
            $this->finishDownload();
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    public function getContentRange(HttpResponse $response): ?array {
        $value = $response->getHeader('Content-Range');
        if ($value === null || !preg_match('/^bytes (\d+)-(\d+)\/(\d+|\*)$/i', trim($value), $matches)) return null;
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        if ($end < $start) return null;
        return ['start' => $start, 'end' => $end, 'total' => $matches[3] === '*' ? null : (int)$matches[3]];
    }

    public function validatePartialResponse(HttpResponse $response, int $requestedOffset): bool {
        $range = $this->getContentRange($response);
        if ($range === null || $range['start'] !== $requestedOffset) return false;
        if ($range['total'] !== null && $range['end'] >= $range['total']) return false;
        $expectedSize = $range['end'] + 1;
        return is_file($this->getPartPath()) && (int)filesize($this->getPartPath()) === $expectedSize;
    }

    public function isCompleteFromRange(HttpResponse $response): bool {
        $value = $response->getHeader('Content-Range');
        if ($value === null || !preg_match('/^bytes \*\/(\d+)$/i', trim($value), $matches)) return false;
        return $this->canResume() && $this->getExistingSize() === (int)$matches[1];
    }

    public function finishDownload(): void {
        $part = $this->getPartPath();
        if (!is_file($part)) throw new \RuntimeException('Download temporary file does not exist.');
        if (file_exists($this->savePath) && !$this->overwrite) throw new \RuntimeException('Destination file already exists.');
        if (@rename($part, $this->savePath)) return;

        if (!file_exists($this->savePath) || !$this->overwrite) {
            throw new \RuntimeException('Unable to finalize downloaded file.');
        }

        $backup = $this->savePath . '.backup-' . bin2hex(random_bytes(6));
        if (!@rename($this->savePath, $backup)) {
            throw new \RuntimeException('Unable to move the existing destination file safely.');
        }
        if (!@rename($part, $this->savePath)) {
            @rename($backup, $this->savePath);
            throw new \RuntimeException('Unable to finalize downloaded file; the previous file was restored.');
        }
        @unlink($backup);
    }

    public function resetProgress(): void {
        $this->lastPercent = -1;
        $this->lastBytes = 0;
        $this->lastTime = 0.0;
        $this->speedHistory = [];
    }

    public function getProgress(float $downloaded, float $total): array {
        $percent = $total > 0 ? round(min(100, max(0, ($downloaded / $total) * 100)), 2) : 0;
        
        $downloadedFormatted = $this->formatBytes($downloaded);
        $totalFormatted = $total > 0 ? $this->formatBytes($total) : 'unknown';
        
        $speed = $this->calculateSpeed($downloaded);
        $eta = $this->calculateETA($downloaded, $total);
        
        return [
            'percent' => $percent,
            'downloaded' => $downloaded,
            'downloaded_formatted' => $downloadedFormatted,
            'total' => $total,
            'total_formatted' => $totalFormatted,
            'speed' => $speed,
            'eta' => $eta,
            'speed_formatted' => $this->formatBytes($speed) . '/s',
            'eta_formatted' => $this->formatTime($eta)
        ];
    }
    
    private function calculateSpeed(float $currentBytes): float {
        $currentTime = microtime(true);
        
        if ($this->lastTime === 0.0) {
            $this->lastTime = $currentTime;
            $this->lastBytes = (int)$currentBytes;
            return 0;
        }
        
        $timeDiff = $currentTime - $this->lastTime;
        if ($timeDiff <= 0.001) {
            return $this->getAverageSpeed();
        }
        
        $bytesDiff = $currentBytes - $this->lastBytes;
        $currentSpeed = $bytesDiff / $timeDiff;
        
        // ذخیره در تاریخچه
        $this->speedHistory[] = $currentSpeed;
        if (count($this->speedHistory) > $this->maxSpeedHistory) {
            array_shift($this->speedHistory);
        }
        
        // آپدیت last bytes و time هر 0.3 ثانیه
        if ($timeDiff >= 0.3) {
            $this->lastBytes = (int)$currentBytes;
            $this->lastTime = $currentTime;
        }
        
        return $this->getAverageSpeed();
    }
    
    private function getAverageSpeed(): float {
        if (empty($this->speedHistory)) {
            return 0;
        }
        
        // میانگین متحرک ساده
        $sum = array_sum($this->speedHistory);
        $avg = $sum / count($this->speedHistory);
        
        // حذف نویز: اگر سرعت خیلی پایین آمد، میانگین وزنی بده
        $lastSpeed = end($this->speedHistory);
        if ($lastSpeed < $avg * 0.3) {
            // وزنه بیشتر به سرعت فعلی
            return ($lastSpeed * 0.7) + ($avg * 0.3);
        }
        
        return $avg > 0 ? $avg : 0;
    }
    
    private function calculateETA(float $downloaded, float $total): int {
        if ($downloaded <= 0 || $total <= 0 || $total <= $downloaded) {
            return 0;
        }
        
        $speed = $this->getAverageSpeed();
        if ($speed <= 0) {
            return 0;
        }
        
        $remaining = $total - $downloaded;
        return (int)($remaining / $speed);
    }
    
    public function callProgress($downloaded, $total): void {
        if ($this->progressCallback !== null) {
            $progress = $this->getProgress((float)$downloaded, (float)$total);
            call_user_func($this->progressCallback, $progress);
        }
        
        if ($this->consoleMode) {
            $this->renderConsoleProgress((float)$downloaded, (float)$total);
        }
    }
    
    private function renderConsoleProgress(float $downloaded, float $total): void {
        if ($total <= 0) {
            return;
        }
        
        $percent = ($downloaded / $total) * 100;
        $percentInt = (int)floor($percent);
        
        // فقط هر 1% تغییر کند یا در انتها آپدیت کن
        if ($percentInt === $this->lastPercent && $percentInt > 0 && $percentInt < 100 && $downloaded < $total) {
            return;
        }
        $this->lastPercent = $percentInt;
        
        $filledWidth = (int)($this->barWidth * $percent / 100);
        $emptyWidth = $this->barWidth - $filledWidth;
        
        $bar = '[' . str_repeat('█', $filledWidth) . str_repeat('░', $emptyWidth) . ']';
        
        $downloadedFormatted = $this->formatBytes($downloaded);
        $totalFormatted = $this->formatBytes($total);
        
        $speed = $this->getAverageSpeed();
        $speedFormatted = $this->formatBytes($speed) . '/s';
        
        $eta = $this->calculateETA($downloaded, $total);
        $etaFormatted = $this->formatTime($eta);
        
        // ساخت خط پروگرس - بدون newline اضافی
        $line = sprintf(
            "\r%s %5.1f%% | %s / %s | %s | ETA: %s",
            $bar,
            $percent,
            $downloadedFormatted,
            $totalFormatted,
            $speedFormatted,
            $etaFormatted
        );
        
        // پاک کردن خط قبلی و نوشتن خط جدید
        echo $line;
        
        // در انتها خط جدید بزن
        if ($percent >= 99.9 || $downloaded >= $total) {
            echo PHP_EOL;
            $this->lastPercent = -1; // ریست برای دانلود بعدی
            $this->speedHistory = []; // پاک کردن تاریخچه سرعت
        }
    }
    
    public function formatBytes(float $bytes, int $precision = 2): string {
        if ($bytes <= 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    private function formatTime(int $seconds): string {
        if ($seconds <= 0) {
            return 'unknown';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf("%02d:%02d", $minutes, $secs);
        } else {
            return sprintf("%02ds", $secs);
        }
    }
    
    public function getMaxRetries(): ?int {
        return $this->maxRetries;
    }
    
    public function getRetryDelay(): int {
        return $this->retryDelay;
    }
    
    public function formatBytesPublic(float $bytes, int $precision = 2): string {
        return $this->formatBytes($bytes, $precision);
    }
}