<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Dynamic class operand for instanceof / `new $expr` / `$c::method()` / `$c::CONST`.
 *
 * Zend requires string or object; other types Error (not stringify → Class "…" not found).
 *
 * php-src: Zend/zend_execute.c — ZEND_INSTANCEOF / ZEND_NEW / ZEND_FETCH_CLASS /
 * ZEND_INIT_STATIC_METHOD_CALL (Z_OBJCE_P / class name fetch).
 *
 * @see #4339 instanceof · #30058 new $object · #30059 static call / class const
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
