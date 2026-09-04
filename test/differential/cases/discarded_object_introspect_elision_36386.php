<?php

declare(strict_types=1);

/**
 * Discarded get_class / get_parent_class / spl_object_* must not change
 * observable get_class output (#36386).
 *
 * php-src: Zend/zend_builtin_functions.c, ext/spl/php_spl.c
 */

class Base36386 {}
class Child36386 extends Base36386 {}

function work(): string
{
    $o = new Child36386();
    get_class($o);
    get_parent_class($o);
    spl_object_id($o);
    spl_object_hash($o);

    return get_class($o);
}

echo work(), "\n";
