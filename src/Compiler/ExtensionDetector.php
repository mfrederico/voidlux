<?php

declare(strict_types=1);

namespace VoidLux\Compiler;

/**
 * Scans PHP files to detect required extensions.
 */
class ExtensionDetector
{
    private const EXTENSION_PATTERNS = [
        'swoole' => [
            '/\bSwoole\\\\/i',
            '/\bOpenSwoole\\\\/i',
            '/\bswoole_/i',
            '/\bco::/i',
        ],
        'pdo_sqlite' => [
            '/new\s+PDO\s*\(\s*[\'"]sqlite:/i',
            '/\bpdo_sqlite\b/i',
        ],
        'pdo_mysql' => [
            '/new\s+PDO\s*\(\s*[\'"]mysql:/i',
            '/\bpdo_mysql\b/i',
        ],
        'sockets' => [
            '/\bsocket_/i',
            '/\bSOCK_STREAM\b/',
            '/\bAF_INET\b/',
        ],
        'curl' => [
            '/\bcurl_/i',
            '/\bCURLOPT_/i',
        ],
        'json' => [
            '/\bjson_encode\b/i',
            '/\bjson_decode\b/i',
        ],
        'mbstring' => [
            '/\bmb_/i',
        ],
        'openssl' => [
            '/\bopenssl_/i',
        ],
        'zlib' => [
            '/\bgzcompress\b/i',
            '/\bgzuncompress\b/i',
            '/\bgzencode\b/i',
        ],
        'dom' => [
            '/\bDOMDocument\b/i',
            '/\bDOMXPath\b/i',
        ],
        'xml' => [
            '/\bsimplexml_/i',
            '/\bSimpleXMLElement\b/i',
        ],
        'redis' => [
            '/\bnew\s+\\\\?Redis\b/i',
        ],
    ];

    /**
     * Scan a directory for PHP files and detect required extensions.
     * @return string[] List of extension names
     */
    public function detect(string $directory): array
    {
        $extensions = [];
        $files = $this->findPhpFiles($directory);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            foreach (self::EXTENSION_PATTERNS as $ext => $patterns) {
                if (isset($extensions[$ext])) {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $content)) {
                        $extensions[$ext] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($extensions);
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
