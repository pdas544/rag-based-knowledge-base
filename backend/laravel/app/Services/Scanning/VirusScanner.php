<?php

namespace App\Services\Scanning;

class VirusScanner
{
    public function __construct(
        private bool $enabled = false,
        private string $binary = 'clamscan',
        private int $timeout = 60,
    ) {}

    /**
     * Scan an uploaded file before parsing/embedding.
     *
     * Disabled by default (no clamd in the base stack). When enabled but the
     * scanner is missing, fail closed instead of silently accepting the file.
     *
     * @throws InfectedFileException when clamd reports malware (exit code 1)
     * @throws \RuntimeException on scanner errors (exit code >= 2)
     */
    public function scan(string $absolutePath): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Source file missing: '.$absolutePath);
        }

        $binary = trim((string) shell_exec('command -v '.escapeshellarg($this->binary).' 2>/dev/null'));
        if ($binary === '') {
            throw new \RuntimeException('ClamAV binary not available: '.$this->binary);
        }

        $cmd = 'timeout '.max(1, $this->timeout).'s '
            .escapeshellcmd($binary).' --no-summary --stdout '
            .escapeshellarg($absolutePath).' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        if ($code === 1) {
            throw new InfectedFileException('Malware detected: '.trim(implode(' ', $output)));
        }

        if ($code !== 0) {
            throw new \RuntimeException('Virus scan failed (exit '.$code.'): '.mb_substr(trim(implode(' ', $output)), 0, 300));
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }
}
