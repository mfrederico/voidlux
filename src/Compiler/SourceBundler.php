<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

use VoidLux\Template\TemplateEngine;

/**
 * Bundles application source into a staging directory for compilation.
 * Generates a self-contained main.php entry point.
 */
class SourceBundler
{
    public function __construct(
        private readonly TemplateEngine $templateEngine,
    ) {}

    /**
     * Bundle project sources into the staging directory.
     */
    public function bundle(string $projectDir, string $stagingDir, string $entryPoint = ''): bool
    {
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        // Copy all source files
        $this->copyDirectory($projectDir, $stagingDir, [
            'vendor', 'build', 'data', '.git', 'node_modules',
            '.claude', 'tests', '.gitignore',
        ]);

        // Generate main.php entry point
        $mainPhp = $this->generateMainPhp($stagingDir, $entryPoint);
        file_put_contents($stagingDir . '/main.php', $mainPhp);

        return true;
    }

    private function generateMainPhp(string $stagingDir, string $entryPoint): string
    {
        $bootstrapTemplate = dirname(__DIR__, 2) . '/templates/compiled-bootstrap.php.tpl';

        if (file_exists($bootstrapTemplate)) {
            $this->templateEngine->setVariable('ENTRY_POINT', $entryPoint ?: 'index.php');
            $this->templateEngine->setVariable('TIMESTAMP', gmdate('Y-m-d\TH:i:s\Z'));
            return $this->templateEngine->renderFile($bootstrapTemplate);
        }

        // Fallback: generate inline
        $autoload = '';
        if (file_exists($stagingDir . '/vendor/autoload.php')) {
            $autoload = "require __DIR__ . '/vendor/autoload.php';";
        }

        $entry = $entryPoint ?: 'index.php';
        return <<<PHP
<?php
// VoidLux compiled binary bootstrap
// Generated: {$this->now()}
{$autoload}
require __DIR__ . '/{$entry}';
PHP;
    }

    private function copyDirectory(string $src, string $dst, array $exclude = []): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $iterator = new \DirectoryIterator($src);
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            $name = $item->getFilename();
            if (in_array($name, $exclude, true)) {
                continue;
            }

            $srcPath = $item->getPathname();
            $dstPath = $dst . '/' . $name;

            if ($item->isDir()) {
                $this->copyDirectory($srcPath, $dstPath, []);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
