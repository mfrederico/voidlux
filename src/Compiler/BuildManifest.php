<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

/**
 * Build metadata written alongside the compiled binary.
 */
class BuildManifest
{
    public function __construct(
        public readonly string $appName,
        public readonly string $appVersion,
        public readonly array $extensions,
        public readonly string $phpVersion,
        public readonly string $builtAt,
        public readonly string $sourceDir,
        public readonly string $outputPath,
    ) {}

    public static function create(
        string $appName,
        array $extensions,
        string $sourceDir,
        string $outputPath,
        string $appVersion = '1.0.0',
    ): self {
        return new self(
            appName: $appName,
            appVersion: $appVersion,
            extensions: $extensions,
            phpVersion: PHP_VERSION,
            builtAt: gmdate('Y-m-d\TH:i:s\Z'),
            sourceDir: $sourceDir,
            outputPath: $outputPath,
        );
    }

    public function toArray(): array
    {
        return [
            'app_name' => $this->appName,
            'app_version' => $this->appVersion,
            'extensions' => $this->extensions,
            'php_version' => $this->phpVersion,
            'built_at' => $this->builtAt,
            'source_dir' => $this->sourceDir,
            'output_path' => $this->outputPath,
        ];
    }

    public function save(string $path): void
    {
        file_put_contents(
            $path,
            json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
