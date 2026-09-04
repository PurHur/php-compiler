<?php

declare(strict_types=1);

/**
 * Discarded is_a / is_subclass_of must not change observable is_a output (#36386).
 *
 * php-src: Zend/zend_builtin_functions.c
 */

class Base36386Isa {}
class Child36386Isa extends Base36386Isa {}

function work(): string
{
    $o = new Child36386Isa();
    is_a($o, 'Base36386Isa');
    is_subclass_of($o, 'Base36386Isa');
    is_a($o, 'Base36386Isa', false);
    is_subclass_of($o, 'Base36386Isa', true);

    return (is_a($o, 'Base36386Isa') ? '1' : '0')
        . (is_subclass_of($o, 'Base36386Isa') ? '1' : '0')
        . (is_a($o, 'Child36386Isa') ? '1' : '0')
        . (is_subclass_of($o, 'Child36386Isa') ? '1' : '0');
}

echo work(), "\n";
