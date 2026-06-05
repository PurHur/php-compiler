<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Dynamic RHS for instanceof — Zend ZEND_INSTANCEOF class operand (#4339).
 *
 * php-src: Zend/zend_execute.c — class name must be string or object.
 */
final class InstanceOfClassName
{
    public const ERROR_MESSAGE = 'Class name must be a valid object or a string';

    public static function resolveClassName(Variable $rhs): string
    {
        $v = $rhs->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            return strtolower($v->toString());
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return strtolower($v->toObject()->class->name);
        }

        throw new \Error(self::ERROR_MESSAGE);
    }
}
