<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * Static seed peer list for WAN/demo bootstrap.
 * Connects to known peers on startup.
 */
class SeedPeers
{
    /** @var array<array{host: string, port: int}> */
    private array $seeds = [];

    /**
     * @param string[] $seedList Array of "host:port" strings
     */
    public function __construct(array $seedList = [])
    {
        foreach ($seedList as $entry) {
            $parts = explode(':', $entry);
            if (count($parts) === 2) {
                $this->seeds[] = [
                    'host' => $parts[0],
                    'port' => (int) $parts[1],
                ];
            }
        }
    }

    /**
     * @return array<array{host: string, port: int}>
     */
    public function getSeeds(): array
    {
        return $this->seeds;
    }

    public function addSeed(string $host, int $port): void
    {
        foreach ($this->seeds as $seed) {
            if ($seed['host'] === $host && $seed['port'] === $port) {
                return;
            }
        }
        $this->seeds[] = ['host' => $host, 'port' => $port];
    }
}
