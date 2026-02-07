<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

/**
 * Resolves composer dependencies for bundling.
 * Runs `composer install --no-dev` into the staging directory.
 */
class DependencyResolver
{
    /**
     * Resolve dependencies for a project into the staging directory.
     */
    public function resolve(string $projectDir, string $stagingDir): bool
    {
        $composerJson = $projectDir . '/composer.json';
        if (!file_exists($composerJson)) {
            echo "  No composer.json found, skipping dependency resolution\n";
            return true;
        }

        // Copy composer files to staging
        copy($composerJson, $stagingDir . '/composer.json');
        if (file_exists($projectDir . '/composer.lock')) {
            copy($projectDir . '/composer.lock', $stagingDir . '/composer.lock');
        }

        // Run composer install
        $cmd = sprintf(
            'cd %s && composer install --no-dev --no-interaction --optimize-autoloader 2>&1',
            escapeshellarg($stagingDir)
        );

        echo "  Running: composer install --no-dev\n";
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            echo "  Composer install failed:\n";
            echo implode("\n", $output) . "\n";
            return false;
        }

        return true;
    }

    /**
     * Rewrite MYCTOBOT_ROOT references in PHP files.
     */
    public function rewritePaths(string $stagingDir, array $replacements): int
    {
        $count = 0;
        $files = $this->findPhpFiles($stagingDir);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $original = $content;

            foreach ($replacements as $search => $replace) {
                $content = str_replace($search, $replace, $content);
            }

            if ($content !== $original) {
                file_put_contents($file, $content);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return string[]
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
