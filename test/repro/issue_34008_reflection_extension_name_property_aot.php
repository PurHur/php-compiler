<?php

declare(strict_types=1);

/**
 * Minimal repro for #34008 — ReflectionExtension::$name under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension___construct
 */
$r = new ReflectionExtension('standard');
echo $r->name, '|', $r->getName(), PHP_EOL;
