<?php

declare(strict_types=1);

namespace App\Service;

final class PrimeSetSnapshotStore
{
    private const SNAPSHOT_FILE = 'prime-set-ranking.json';
    private const REFRESH_LOCK_FILE = 'prime-set-refresh.lock';
    private const REFRESH_STATUS_FILE = 'prime-set-refresh-status.json';
    private const REFRESH_LOG_FILE = 'prime-set-refresh.log';
    private const STALE_LOCK_SECONDS = 1800;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadSnapshot(): ?array
    {
        $path = $this->snapshotPath();
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function saveSnapshot(array $snapshot): void
    {
        $snapshot['savedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->ensureDataDirectory();
        file_put_contents($this->snapshotPath(), json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadRefreshStatus(): ?array
    {
        $path = $this->refreshStatusPath();
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $status
     */
    public function saveRefreshStatus(array $status): void
    {
        $status['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->ensureDataDirectory();
        file_put_contents($this->refreshStatusPath(), json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function isRefreshing(): bool
    {
        $path = $this->refreshLockPath();
        if (!is_file($path)) {
            return false;
        }

        $age = time() - (filemtime($path) ?: time());
        if ($age > self::STALE_LOCK_SECONDS) {
            @unlink($path);
            $this->saveRefreshStatus([
                'status' => 'stale',
                'message' => 'The previous refresh appears to have stalled.',
                'logPath' => $this->refreshLogPath(),
            ]);
            return false;
        }

        return true;
    }

    public function markRefreshing(): void
    {
        $this->ensureDataDirectory();
        file_put_contents($this->refreshLockPath(), (new \DateTimeImmutable())->format(DATE_ATOM));
    }

    public function clearRefreshing(): void
    {
        @unlink($this->refreshLockPath());
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getRefreshLogPath(): string
    {
        return $this->refreshLogPath();
    }

    private function snapshotPath(): string
    {
        return $this->projectDir.'/var/data/'.self::SNAPSHOT_FILE;
    }

    private function ensureDataDirectory(): void
    {
        $directory = $this->projectDir.'/var/data';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    private function refreshLockPath(): string
    {
        return $this->projectDir.'/var/data/'.self::REFRESH_LOCK_FILE;
    }

    private function refreshStatusPath(): string
    {
        return $this->projectDir.'/var/data/'.self::REFRESH_STATUS_FILE;
    }

    private function refreshLogPath(): string
    {
        return $this->projectDir.'/var/data/'.self::REFRESH_LOG_FILE;
    }
}
