<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Dynamic class operand for instanceof / `new $expr` — Zend string or object (#4339, #30058).
 *
 * php-src: Zend/zend_execute.c — ZEND_INSTANCEOF / ZEND_NEW class fetch (Z_OBJCE_P).
 */
final class InstanceOfClassName
{
    public const ERROR_MESSAGE = 'Class name must be a valid object or a string';

    /**
     * Class name as stored on the operand (string value or object's class entry name).
     */
    public static function resolveClassNamePreservingCase(Variable $rhs): string
    {
        $v = $rhs->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            return $v->toString();
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return $v->toObject()->class->name;
        }

        throw new \Error(self::ERROR_MESSAGE);
    }

    public static function resolveClassName(Variable $rhs): string
    {
        return strtolower(self::resolveClassNamePreservingCase($rhs));
    }
}
