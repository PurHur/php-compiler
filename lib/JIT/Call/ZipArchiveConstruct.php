<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\zip\VmZipArchive;
use PHPCompiler\ext\zip\ZipArchiveConstants;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive::__construct — seed thin-AOT stub properties (#35002 leftover of #20584).
 *
 * php-src: ext/zip/php_zip.c — ze_zip_object defaults (last_id=-1, status=ER_OK, strings '').
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class ZipArchiveConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
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
            \PHPCompiler\ext\zip\ZipArchiveJitSupport::PROP_ID,
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

    private static function objectPtr(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException(
            'ZipArchive::__construct() expects an object, got '
            .Variable::getStringType($receiver->type)
        );
    }
}
