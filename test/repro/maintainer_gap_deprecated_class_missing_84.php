<?php

declare(strict_types=1);

/**
 * Maintainer repro: Deprecated builtin attribute class on forward profile (#17318).
 *
 * php-src: Zend/zend_attributes.c — built-in Deprecated attribute registration
 */

if (!class_exists('Deprecated', false)) {
    echo "fail: Deprecated class missing\n";
    exit(1);
}

#[\Deprecated(message: 'legacy', since: '8.4')]
class Legacy {}

$rc = new ReflectionClass(Legacy::class);
$attrs = $rc->getAttributes();
if (1 !== count($attrs) || 'Deprecated' !== $attrs[0]->getName()) {
    echo "fail: Legacy missing Deprecated attribute\n";
    exit(1);
}

$inst = $attrs[0]->newInstance();
if ('legacy' !== $inst->message || '8.4' !== $inst->since) {
    echo "fail: Deprecated attribute args\n";
    exit(1);
}

echo "ok\n";
