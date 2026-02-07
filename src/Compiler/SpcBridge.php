<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

/**
 * Wraps static-php-cli (spc) commands for building PHP micro binaries.
 */
class SpcBridge
{
    private string $spcDir;
    private string $spcBin;

    public function __construct(?string $spcDir = null)
    {
        $this->spcDir = $spcDir ?? ($_SERVER['HOME'] ?? '/root') . '/.voidlux/spc';
        $this->spcBin = $this->spcDir . '/bin/spc';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->spcBin);
    }

    public function getSpcDir(): string
    {
        return $this->spcDir;
    }

    /**
     * Download PHP source and extension sources.
     * @param string[] $extensions
     */
    public function download(array $extensions): bool
    {
        $extList = implode(',', $extensions);
        $cmd = sprintf(
            '%s download --with-php=8.3 --for-extensions=%s 2>&1',
            escapeshellarg($this->spcBin),
            escapeshellarg($extList)
        );

        echo "  Running: spc download --for-extensions={$extList}\n";
        return $this->exec($cmd);
    }

    /**
     * Build the PHP micro binary with the specified extensions.
     * @param string[] $extensions
     */
    public function buildMicro(array $extensions): bool
    {
        $extList = implode(',', $extensions);
        $cmd = sprintf(
            '%s build --build-micro %s 2>&1',
            escapeshellarg($this->spcBin),
            escapeshellarg($extList)
        );

        echo "  Running: spc build --build-micro {$extList}\n";
        return $this->exec($cmd);
    }

    /**
     * Combine the micro binary with a PHP application.
     */
    public function combine(string $mainPhp, string $outputPath): bool
    {
        $cmd = sprintf(
            '%s micro:combine %s --output=%s 2>&1',
            escapeshellarg($this->spcBin),
            escapeshellarg($mainPhp),
            escapeshellarg($outputPath)
        );

        echo "  Running: spc micro:combine\n";
        return $this->exec($cmd);
    }

    /**
     * Get the path to the built micro binary.
     */
    public function getMicroBinaryPath(): string
    {
        return $this->spcDir . '/buildroot/bin/micro.sfx';
    }

    private function exec(string $cmd): bool
    {
        $cwd = getcwd();
        chdir($this->spcDir);

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        chdir($cwd);

        if ($returnCode !== 0) {
            echo "  Command failed (exit code {$returnCode}):\n";
            echo implode("\n", array_slice($output, -20)) . "\n";
            return false;
        }

        return true;
    }
}
