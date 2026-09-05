<?php

declare(strict_types=1);

/**
 * AOT (#36382): SlimHttpServerRequestCreator::isServerRequestDecoratorAvailable() uses
 * `class_exists(static::$serverRequestDecoratorClass)` — LSB typed static strings crash
 * the class_exists path under AOT. Use a string literal (always false without slim/http).
 *
 * php-src: Zend/zend_builtin_functions.c class_exists.
 *
 * Usage: php script/composer/patch-slim-http-src-decorator-36382.php path/to/SlimHttpServerRequestCreator.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} SlimHttpServerRequestCreator.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): literal class_exists for decorator')) {
    echo "SlimHttpServerRequestCreator.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
    public static function isServerRequestDecoratorAvailable(): bool
    {
        return class_exists(static::$serverRequestDecoratorClass);
    }
PHP;
$new = <<<'PHP'
    public static function isServerRequestDecoratorAvailable(): bool
    {
        // AOT (#36382): literal class_exists for decorator — LSB typed static strings
        // crash class_exists(static::$x) under AOT. slim/http is optional; literal is false.
        return class_exists('Slim\\Http\\ServerRequest');
    }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "SlimHttpServerRequestCreator pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text));
echo "patched SlimHttpServerRequestCreator for AOT (#36382)\n";
