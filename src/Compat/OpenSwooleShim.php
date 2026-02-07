<?php

declare(strict_types=1);

namespace VoidLux\Compat;

/**
 * Aliases OpenSwoole\* namespaces to Swoole\* for compatibility.
 * static-php-cli ships Swoole, not OpenSwoole. MyCTOBot apps use OpenSwoole namespaces.
 */
class OpenSwooleShim
{
    private static bool $registered = false;

    private static array $aliases = [
        'OpenSwoole\\Http\\Server'      => 'Swoole\\Http\\Server',
        'OpenSwoole\\Http\\Request'     => 'Swoole\\Http\\Request',
        'OpenSwoole\\Http\\Response'    => 'Swoole\\Http\\Response',
        'OpenSwoole\\WebSocket\\Server' => 'Swoole\\WebSocket\\Server',
        'OpenSwoole\\WebSocket\\Frame'  => 'Swoole\\WebSocket\\Frame',
        'OpenSwoole\\Server'            => 'Swoole\\Server',
        'OpenSwoole\\Table'             => 'Swoole\\Table',
        'OpenSwoole\\Timer'             => 'Swoole\\Timer',
        'OpenSwoole\\Coroutine'         => 'Swoole\\Coroutine',
        'OpenSwoole\\Coroutine\\Http\\Client' => 'Swoole\\Coroutine\\Http\\Client',
        'OpenSwoole\\Coroutine\\Client' => 'Swoole\\Coroutine\\Client',
        'OpenSwoole\\Coroutine\\Socket' => 'Swoole\\Coroutine\\Socket',
        'OpenSwoole\\Coroutine\\Channel' => 'Swoole\\Coroutine\\Channel',
        'OpenSwoole\\Process'           => 'Swoole\\Process',
        'OpenSwoole\\Atomic'            => 'Swoole\\Atomic',
    ];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        foreach (self::$aliases as $openSwoole => $swoole) {
            if (class_exists($swoole, false) && !class_exists($openSwoole, false)) {
                class_alias($swoole, $openSwoole);
            }
        }

        // Register autoloader for any OpenSwoole classes we haven't explicitly mapped
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'OpenSwoole\\')) {
                $swooleClass = str_replace('OpenSwoole\\', 'Swoole\\', $class);
                if (class_exists($swooleClass, false)) {
                    class_alias($swooleClass, $class);
                }
            }
        });

        self::$registered = true;
    }
}
