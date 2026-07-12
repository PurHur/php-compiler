<?php

declare(strict_types=1);

/**
 * Issue #18337 — ReflectionParameter on internal functions (strlen).
 *
 * php-src: ext/reflection/php_reflection.c
 */

$p = new ReflectionParameter('strlen', 0);
echo $p->getName(), "\n";
echo $p->getType()->getName(), "\n";

$map = new ReflectionParameter('array_map', 0);
echo $map->getName(), "\n";
echo $map->getType()->getName(), "\n";
