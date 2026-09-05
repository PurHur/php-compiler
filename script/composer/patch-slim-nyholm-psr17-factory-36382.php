<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim NyholmPsr17Factory stores FQCNs as typed static strings and uses
 * `class_exists(static::$serverRequestCreatorClass)` / `new static::$responseFactoryClass()`.
 * Under AOT, LSB typed static strings print/compare correctly but crash or empty when used
 * as class_exists / `new $cls` operands (native-string vs value-box mismatch on the
 * introspection / dynamic-new path). Rewrite to concrete Nyholm class refs — Zend-equivalent
 * for the only installed PSR-17 backend in the Slim hello fixture.
 *
 * php-src: Zend/zend_builtin_functions.c class_exists; Zend/zend_vm_def.h ZEND_NEW.
 *
 * Usage: php script/composer/patch-slim-nyholm-psr17-factory-36382.php path/to/NyholmPsr17Factory.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} NyholmPsr17Factory.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): concrete Nyholm class refs')) {
    echo "NyholmPsr17Factory.php already patched (#36382)\n";
    exit(0);
}

$oldAvail = <<<'PHP'
    public static function isServerRequestCreatorAvailable(): bool
    {
        return (
            parent::isServerRequestCreatorAvailable()
            && class_exists(static::$responseFactoryClass)
        );
    }
PHP;
$newAvail = <<<'PHP'
    public static function isServerRequestCreatorAvailable(): bool
    {
        // AOT (#36382): concrete Nyholm class refs — LSB typed static strings crash
        // class_exists(static::$x) / new static::$x under AOT (Zend-equivalent here).
        return class_exists(\Nyholm\Psr7Server\ServerRequestCreator::class)
            && class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class);
    }
PHP;

$oldGet = <<<'PHP'
    public static function getServerRequestCreator(): ServerRequestCreatorInterface
    {
        /*
         * Nyholm Psr17Factory implements all factories in one unified
         * factory which implements all of the PSR-17 factory interfaces
         */
        $psr17Factory = new static::$responseFactoryClass();

        $serverRequestCreator = new static::$serverRequestCreatorClass(
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
            $psr17Factory
        );

        return new ServerRequestCreator($serverRequestCreator, static::$serverRequestCreatorMethod);
    }
PHP;
$newGet = <<<'PHP'
    public static function getServerRequestCreator(): ServerRequestCreatorInterface
    {
        /*
         * Nyholm Psr17Factory implements all factories in one unified
         * factory which implements all of the PSR-17 factory interfaces
         */
        // AOT (#36382): concrete Nyholm class refs (see isServerRequestCreatorAvailable).
        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        $serverRequestCreator = new \Nyholm\Psr7Server\ServerRequestCreator(
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
            $psr17Factory
        );

        return new ServerRequestCreator($serverRequestCreator, 'fromGlobals');
    }
PHP;

if (!str_contains($text, $oldAvail) || !str_contains($text, $oldGet)) {
    fwrite(STDERR, "NyholmPsr17Factory patterns not found\n");
    exit(1);
}
$text = str_replace($oldAvail, $newAvail, $text);
$text = str_replace($oldGet, $newGet, $text);
file_put_contents($path, $text);
echo "patched NyholmPsr17Factory for AOT (#36382)\n";
