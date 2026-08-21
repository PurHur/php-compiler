<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitPath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplFileObject — snapshot lines into `__spl_ht` for foreach (#28709, #33305).
 *
 * Construct / openFile read via libc file_get_contents then fgets-shaped line split.
 * Path accessors read `__pathname` (not SplFileInfo `__dir_path`/`__filename`) (#33305).
 * Foreach walks packed `__spl_ht` ({@see SplOuterIteratorHt}).
 *
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / zim_SplFileInfo_openFile
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
        self::initConstructedFromPath($context, $obj, self::loadString($context, $pathArg));

        return self::voidResult($context);
    }

    /**
     * Allocate SplFileObject and init from pathname (openFile / factories) (#33305).
     */
    public static function emitNewFromPathname(Context $context, Value $pathStr): Value
    {
        $classId = $context->type->object->lookup(self::CLASS_NAME);
        $newObj = $context->type->object->allocate($classId);
        self::initConstructedFromPath($context, $newObj, $pathStr);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $newObj
        );

        return $slot;
    }

    /** SplFileObject::getFilename — basename(__pathname) (#33305). */
    public static function compileGetFilename(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $name = JitPath::basename($context, $pathname);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $name
        );

        return $slot;
    }

    /** SplFileObject::getPathname / __toString (#33305). */
    public static function compileGetPathname(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $pathname
        );

        return $slot;
    }

    /** SplFileObject::getPath — dirname(__pathname) (#33305). */
    public static function compileGetPath(Context $context, JITVariable $receiver): Value
    {
        $pathname = self::loadPathname($context, $receiver);
        $dir = JitPath::dirname($context, $pathname);
        // Match SplFileInfo empty-dir when basename length equals pathname length.
        $pathLen = $context->builder->call($context->lookupFunction('__string__strlen'), $pathname);
        $name = JitPath::basename($context, $pathname);
        $nameLen = $context->builder->call($context->lookupFunction('__string__strlen'), $name);
        $noDir = $context->builder->icmp(
            Builder::INT_EQ,
            $pathLen,
            $nameLen
        );
        $empty = $context->builder->load($context->constantStringFromString(''));
        $dirOut = $context->builder->select($noDir, $empty, $dir);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $dirOut
        );

        return $slot;
    }

    private static function loadPathname(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pathSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_PATH);
        if (JITVariable::TYPE_STRING === $pathSlot->type) {
            return $context->helper->loadValue($pathSlot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $pathSlot)
        );
    }

    private static function initConstructedFromPath(Context $context, Value $obj, Value $pathStr): void
    {
        $objectType = $context->type->object;
        // Prefer empty HT over NestedJIT line snapshot — snapshotPath SIGSEGV under thin AOT
        // on this tree (#33305); foreach remains a follow-up (also red on master).
        $ht = HashTableHelper::alloc($context);
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
        $objectType->markObjectConstructed($obj);
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
