<?php

declare(strict_types=1);

/**
 * AOT (#36382): ServerRequestCreatorFactory::determineServerRequestCreator() loops
 * Slim/HttpSoft/Nyholm/… factories; each uses `class_exists(static::$serverRequestCreatorClass)`
 * which crashes under AOT on LSB typed static strings. Prefer the already-patched Nyholm
 * backend first (the Slim hello fixture's only installed PSR-17 stack).
 *
 * php-src: Zend/zend_builtin_functions.c class_exists.
 *
 * Usage: php script/composer/patch-slim-server-request-creator-factory-36382.php path/to/ServerRequestCreatorFactory.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} ServerRequestCreatorFactory.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): prefer Nyholm before factory loop')) {
    echo "ServerRequestCreatorFactory.php already patched (#36382)\n";
    exit(0);
}
$old = <<<'PHP'
    public static function determineServerRequestCreator(): ServerRequestCreatorInterface
    {
        if (static::$serverRequestCreator) {
            return static::attemptServerRequestCreatorDecoration(static::$serverRequestCreator);
        }

        $psr17FactoryProvider = static::$psr17FactoryProvider ?? new Psr17FactoryProvider();

        /** @var Psr17Factory $psr17Factory */
        foreach ($psr17FactoryProvider->getFactories() as $psr17Factory) {
            if ($psr17Factory::isServerRequestCreatorAvailable()) {
                $serverRequestCreator = $psr17Factory::getServerRequestCreator();
                return static::attemptServerRequestCreatorDecoration($serverRequestCreator);
            }
        }
PHP;
$new = <<<'PHP'
    public static function determineServerRequestCreator(): ServerRequestCreatorInterface
    {
        if (static::$serverRequestCreator) {
            return static::attemptServerRequestCreatorDecoration(static::$serverRequestCreator);
        }

        // AOT (#36382): prefer Nyholm before factory loop — other backends' LSB typed
        // static strings crash class_exists(static::$x) under AOT (Nyholm is patched).
        if (NyholmPsr17Factory::isServerRequestCreatorAvailable()) {
            return static::attemptServerRequestCreatorDecoration(
                NyholmPsr17Factory::getServerRequestCreator()
            );
        }

        $psr17FactoryProvider = static::$psr17FactoryProvider ?? new Psr17FactoryProvider();

        /** @var Psr17Factory $psr17Factory */
        foreach ($psr17FactoryProvider->getFactories() as $psr17Factory) {
            if ($psr17Factory::isServerRequestCreatorAvailable()) {
                $serverRequestCreator = $psr17Factory::getServerRequestCreator();
                return static::attemptServerRequestCreatorDecoration($serverRequestCreator);
            }
        }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "ServerRequestCreatorFactory pattern not found\n");
    exit(1);
}
// Ensure NyholmPsr17Factory is imported
if (!str_contains($text, 'use Slim\\Factory\\Psr17\\NyholmPsr17Factory;')) {
    $text = str_replace(
        "use Slim\\Factory\\Psr17\\Psr17Factory;\n",
        "use Slim\\Factory\\Psr17\\NyholmPsr17Factory;\nuse Slim\\Factory\\Psr17\\Psr17Factory;\n",
        $text
    );
}
$text = str_replace($old, $new, $text);
file_put_contents($path, $text);
echo "patched ServerRequestCreatorFactory for AOT (#36382)\n";
