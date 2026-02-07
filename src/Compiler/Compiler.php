<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

use VoidLux\Template\TemplateEngine;

/**
 * Build orchestrator: detect → resolve → bundle → download → build → combine.
 */
class Compiler
{
    private ExtensionDetector $detector;
    private DependencyResolver $resolver;
    private SourceBundler $bundler;
    private SpcBridge $spc;

    public function __construct(?string $spcDir = null)
    {
        $this->detector = new ExtensionDetector();
        $this->resolver = new DependencyResolver();
        $this->bundler = new SourceBundler(new TemplateEngine());
        $this->spc = new SpcBridge($spcDir);
    }

    /**
     * Compile an application directory into a static binary.
     */
    public function compile(
        string $appDir,
        string $outputPath,
        string $entryPoint = '',
        string $appName = '',
    ): bool {
        $appDir = realpath($appDir);
        if (!$appDir || !is_dir($appDir)) {
            echo "Error: App directory not found: {$appDir}\n";
            return false;
        }

        if (!$this->spc->isInstalled()) {
            echo "Error: static-php-cli is not installed.\n";
            echo "Run: scripts/install-spc.sh\n";
            return false;
        }

        $appName = $appName ?: basename($appDir);
        $buildDir = dirname(__DIR__, 2) . '/build';
        $stagingDir = $buildDir . '/staging';

        // Clean staging
        if (is_dir($stagingDir)) {
            $this->removeDirectory($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        echo "=== VoidLux Compiler ===\n";
        echo "App: {$appName}\n";
        echo "Source: {$appDir}\n\n";

        // Step 1: Detect extensions
        echo "[1/6] Detecting required extensions...\n";
        $extensions = $this->detector->detect($appDir);
        echo "  Found: " . implode(', ', $extensions) . "\n\n";

        // Ensure minimum extensions
        if (!in_array('swoole', $extensions)) {
            $extensions[] = 'swoole';
        }

        // Step 2: Resolve dependencies
        echo "[2/6] Resolving dependencies...\n";
        if (!$this->resolver->resolve($appDir, $stagingDir)) {
            echo "Error: Failed to resolve dependencies\n";
            return false;
        }

        // Rewrite MYCTOBOT_ROOT paths
        $rewritten = $this->resolver->rewritePaths($stagingDir, [
            '{{MYCTOBOT_ROOT}}' => '__DIR__',
            'getenv(\'MYCTOBOT_ROOT\')' => '__DIR__',
        ]);
        if ($rewritten > 0) {
            echo "  Rewrote paths in {$rewritten} files\n";
        }
        echo "\n";

        // Step 3: Bundle sources
        echo "[3/6] Bundling sources...\n";
        if (!$this->bundler->bundle($appDir, $stagingDir, $entryPoint)) {
            echo "Error: Failed to bundle sources\n";
            return false;
        }
        echo "  Staged to: {$stagingDir}\n\n";

        // Step 4: Download PHP sources & extensions
        echo "[4/6] Downloading PHP and extension sources...\n";
        if (!$this->spc->download($extensions)) {
            echo "Error: SPC download failed\n";
            return false;
        }
        echo "\n";

        // Step 5: Build micro binary
        echo "[5/6] Building PHP micro binary...\n";
        if (!$this->spc->buildMicro($extensions)) {
            echo "Error: SPC build failed\n";
            return false;
        }
        echo "\n";

        // Step 6: Combine micro + app
        echo "[6/6] Combining binary...\n";
        if (!$outputPath) {
            $outputPath = $buildDir . '/' . $appName;
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if (!$this->spc->combine($stagingDir . '/main.php', $outputPath)) {
            echo "Error: Failed to combine binary\n";
            return false;
        }

        chmod($outputPath, 0755);

        // Write build manifest
        $manifest = BuildManifest::create($appName, $extensions, $appDir, $outputPath);
        $manifest->save($outputPath . '.manifest.json');

        $size = filesize($outputPath);
        $sizeMb = round($size / 1048576, 1);

        echo "\n=== Build Complete ===\n";
        echo "Binary: {$outputPath} ({$sizeMb} MB)\n";
        echo "Manifest: {$outputPath}.manifest.json\n";

        return true;
    }

    /**
     * Detect extensions without building.
     */
    public function detect(string $appDir): array
    {
        return $this->detector->detect($appDir);
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
