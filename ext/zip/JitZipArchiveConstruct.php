<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ZipArchive::__construct() — seed thin-AOT stub properties.
 *
 * php-src: ext/zip/php_zip.c — ze_zip_object defaults (last_id=-1, status=ER_OK, strings '').
 * Moved from lib/JIT/Call so Call proxies do not import ext/zip (#36204 / #35002).
 */
final class JitZipArchiveConstruct
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('ZipArchive::__construct() called without $this');
        }
        $obj = self::objectPtr($context, $args[0]);
        $class = 'ZipArchive';

        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            $class,
            VmZipArchive::PROP_STATUS,
            ZipArchiveConstants::ER_OK
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            $class,
            VmZipArchive::PROP_STATUS_SYS,
            0
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            $class,
            VmZipArchive::PROP_LAST_ID,
            -1
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            $class,
            VmZipArchive::PROP_NUM_FILES,
            0
        );
        // NestedJIT handle slot — 0 means alloc-on-first-use (#35424).
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            $class,
            ZipArchiveJitSupport::PROP_ID,
            0
        );
        self::seedEmptyString($context, $obj, VmZipArchive::PROP_FILENAME);
        self::seedEmptyString($context, $obj, VmZipArchive::PROP_COMMENT);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function seedEmptyString(Context $context, Value $obj, string $prop): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $len = $context->constantFromInteger(0, 'size_t');
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ZipArchive',
            $prop,
            $empty,
            $len
        );
    }

    private static function objectPtr(Context $context, JITVariable $receiver): Value
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

        throw new \LogicException(
            'ZipArchive::__construct() expects an object, got '
            .JITVariable::getStringType($receiver->type)
        );
    }
}
