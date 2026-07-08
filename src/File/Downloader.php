<?php

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
        $this->url = $url;
        
        if ($savePath === null) {
            $this->filename = basename(parse_url($url, PHP_URL_PATH)) ?: 'download';
            $this->savePath = getcwd() . DIRECTORY_SEPARATOR . $this->filename;
        } else {
            $this->savePath = $savePath;
            $this->filename = basename($savePath);
        }
        
        $this->consoleMode = (php_sapi_name() === 'cli');
    }
    
    public function withResume(bool $resume = true): self {
        $clone = clone $this;
        $clone->resume = $resume;
        return $clone;
    }
    
    public function withProgress(callable $callback): self {
        $clone = clone $this;
        $clone->progressCallback = $callback;
        return $clone;
    }
    
    public function withConsoleProgress(int $barWidth = 50): self {
        $clone = clone $this;
        $clone->consoleMode = true;
        $clone->barWidth = $barWidth;
        return $clone;
    }
    
    public function withChunkSize(int $bytes): self {
        $clone = clone $this;
        $clone->chunkSize = $bytes;
        return $clone;
    }
    
    public function withHeader(string $name, string $value): self {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }
    
    public function withHeaders(array $headers): self {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);
        return $clone;
    }
    
    public function withOverwrite(bool $overwrite = true): self {
        $clone = clone $this;
        $clone->overwrite = $overwrite;
        return $clone;
    }
    
    public function withRetries(int $maxRetries, int $delaySeconds = 1): self {
        $clone = clone $this;
        $clone->maxRetries = $maxRetries;
        $clone->retryDelay = $delaySeconds;
        return $clone;
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
        return $this->resume && file_exists($this->savePath);
    }
    
    public function getExistingSize(): int {
        if ($this->canResume()) {
            return filesize($this->savePath);
        }
        return 0;
    }
    
    public function getRequest(): Request {
        $request = Request::get($this->url);
        
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        
        if ($this->canResume()) {
            $existingSize = $this->getExistingSize();
            $request = $request->withHeader('Range', "bytes=$existingSize-");
        }
        
        return $request;
    }
    
    public function shouldDownload(): bool {
        if (!$this->overwrite && file_exists($this->savePath) && !$this->resume) {
            return false;
        }
        return true;
    }
    
    public function save(HttpResponse $response): bool {
        if (!$response->isSuccessful() && $response->getStatusCode() !== 206) {
            return false;
        }
        
        $dir = dirname($this->savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $mode = $this->canResume() ? 'ab' : 'wb';
        $fp = fopen($this->savePath, $mode);
        
        if ($fp === false) {
            return false;
        }
        
        $bytesWritten = fwrite($fp, $response->getBody());
        fclose($fp);
        
        return $bytesWritten !== false;
    }
    
    public function getProgress(float $downloaded, float $total): array {
        $percent = $total > 0 ? round(($downloaded / $total) * 100, 2) : 0;
        
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