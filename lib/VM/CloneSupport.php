<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend/zend_clones.c — clone operand must be object at runtime (#19097).
 */
final class CloneSupport
{
    public const NON_OBJECT_ERROR_MESSAGE = '__clone method called on non-object';
}
