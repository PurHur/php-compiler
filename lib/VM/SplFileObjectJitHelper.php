<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Builtin\SplFileObjectSnapshotRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Thin-AOT SplFileObject — snapshot lines into `__spl_ht` for foreach (#28709).
 *
 * Construct reads via libc file_get_contents then NestedJIT explode line split.
 * Also initialises SplFileInfo `__dir_path` / `__filename` (#33308).
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileObject___construct
 */
final class SplFileObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_PATH = '__pathname';

    private const CLASS_NAME = 'SplFileObject';

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $pathArg
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $pathStr = self::loadString($context, $pathArg);
        $ht = SplFileObjectSnapshotRuntime::snapshotPath($context, $pathStr);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT),
            $htVar,
            JITVariable::TYPE_HASHTABLE
        );
        $pathVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $pathStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_PATH),
            $pathVar,
            JITVariable::TYPE_STRING
        );
        // Parent SplFileInfo path props — getFilename/getPathname (#33308).
        DirectoryIteratorJitHelper::initSplFileInfoPathProps(
            $context,
            $obj,
            $pathStr,
            self::CLASS_NAME,
            false
        );
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SplFileObject method requires an object receiver');
    }

    private static function loadString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'SplFileObject path must be string, got '.JITVariable::getStringType($arg->type)
        );
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
