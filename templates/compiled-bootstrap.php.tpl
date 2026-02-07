<?php
/**
 * VoidLux Compiled Bootstrap
 * Self-contained entry point for static PHP binary.
 * Generated: {{TIMESTAMP}}
 */

// Register OpenSwoole → Swoole compatibility shim
if (class_exists('Swoole\\Http\\Server') && !class_exists('OpenSwoole\\Http\\Server')) {
    foreach ([
        'OpenSwoole\\Http\\Server'      => 'Swoole\\Http\\Server',
        'OpenSwoole\\Http\\Request'     => 'Swoole\\Http\\Request',
        'OpenSwoole\\Http\\Response'    => 'Swoole\\Http\\Response',
        'OpenSwoole\\WebSocket\\Server' => 'Swoole\\WebSocket\\Server',
        'OpenSwoole\\WebSocket\\Frame'  => 'Swoole\\WebSocket\\Frame',
        'OpenSwoole\\Server'            => 'Swoole\\Server',
        'OpenSwoole\\Table'             => 'Swoole\\Table',
        'OpenSwoole\\Timer'             => 'Swoole\\Timer',
        'OpenSwoole\\Coroutine'         => 'Swoole\\Coroutine',
        'OpenSwoole\\Coroutine\\Client' => 'Swoole\\Coroutine\\Client',
        'OpenSwoole\\Coroutine\\Socket' => 'Swoole\\Coroutine\\Socket',
        'OpenSwoole\\Process'           => 'Swoole\\Process',
    ] as $alias => $target) {
        if (class_exists($target, false) && !class_exists($alias, false)) {
            class_alias($target, $alias);
        }
    }
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'OpenSwoole\\')) {
            $swooleClass = str_replace('OpenSwoole\\', 'Swoole\\', $class);
            if (class_exists($swooleClass, false)) {
                class_alias($swooleClass, $class);
            }
        }
    });
}

// Autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

// Run the application entry point
require __DIR__ . '/{{ENTRY_POINT}}';
