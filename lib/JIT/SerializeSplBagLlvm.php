<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Value;

/**
 * Call-site LLVM serialize() for SPL ArrayObject bag (#34491).
 *
 * NestedJIT `SerializeSplArrayNestedJitHelper::encodeWire` SIGABRTs on non-empty storage
 * (same exportKeyValuePairs class as #34483). Compose {@see SerializeArrayLlvm} + object header.
 *
 * php-src: ext/spl/spl_array.c — ArrayObject::__serialize
 */
final class SerializeSplBagLlvm
{
    /**
     * ArrayObject/ArrayIterator: `O:len:"Class":4:{i:0;i:flags;i:1;a:N:{…}i:2;a:0:{}i:3;N;}`.
     */
    public static function encodeArrayObject(
        Context $context,
        Value $className,
        Value $flagsI64,
        Value $storageHt
    ): Value {
        $header = self::objectHeader($context, $className);
        $flagsDigits = VmResourceIdString::formatNativeLong($context, $flagsI64);
        $storageWire = SerializeArrayLlvm::encode(
            $context,
            $storageHt,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $mid = $context->builder->load($context->constantStringFromString('4:{i:0;i:'));
        $afterFlags = $context->builder->load($context->constantStringFromString(';i:1;'));
        $tail = $context->builder->load($context->constantStringFromString('i:2;a:0:{}i:3;N;}'));

        return JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat(
                        $context,
                        JitStringConcat::concat($context, $header, $mid),
                        $flagsDigits
                    ),
                    $afterFlags
                ),
                $storageWire
            ),
            $tail
        );
    }

    /** NestedJIT {@see SerializeObjectNestedJitHelper::formatObjectHeader} — `O:len:"Class":`. */
    private static function objectHeader(Context $context, Value $className): Value
    {
        $logical = 'PHPCompiler\\ext\\standard\\SerializeObjectNestedJitHelper::formatObjectHeader';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/SerializeObjectNestedJitHelper.php',
            [$logical],
            '#34491'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = JitVmHelperLink::lookupCompiled($context, $logical, '#34491');
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($className, $strMap['length'])
        );
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper($context, $className, $fn->getParam(0)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $classLen, $fn->getParam(1)->typeOf()),
        ];
        $raw = $context->builder->call($fn, ...$args);
        $strPtr = $context->getTypeFromString('__string__*');

        return JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr);
    }
}
