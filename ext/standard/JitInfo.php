<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Builtin\StringPhpinfoRuntime;
use PHPCompiler\JIT\Builtin\StringVersionCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for phpversion/php_uname/php_sapi_name/version introspection (#3174, #3204, #6124). */
final class JitInfo
{
    public static function phpversion(Context $context, ?JITVariable $extension): Value
    {
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $extArg = $strPtr->constNull();
        if (null !== $extension) {
            $extArg = JitStringArg::lower($context, $extension, 'phpversion() extension');
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_phpversion'),
            $extArg
        );

        return self::stringOrFalse($context, $raw, 'phpversion');
    }

    public static function php_sapi_name(Context $context): Value
    {
        StringInfo::ensureLinked($context);
        $raw = $context->builder->call($context->lookupFunction('__compiler_php_sapi_name'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    public static function zend_version(Context $context): Value
    {
        StringInfo::ensureLinked($context);
        $raw = $context->builder->call($context->lookupFunction('__compiler_zend_version'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    public static function phpinfo(Context $context, ?JITVariable $flagsArg): Value
    {
        StringPhpinfoRuntime::ensureLinked($context);
        $flags = JitPhpinfoFlags::resolvePhpinfoFlags($context, $flagsArg);
        $context->builder->call($context->lookupFunction('__compiler_phpinfo'), $flags);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return $ptr;
    }

    public static function phpcredits(Context $context, ?JITVariable $flagsArg): Value
    {
        StringPhpinfoRuntime::ensureLinked($context);
        $flags = JitPhpinfoFlags::resolvePhpcreditsFlags($context, $flagsArg);
        $context->builder->call($context->lookupFunction('__compiler_phpcredits'), $flags);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    public static function php_uname(Context $context, ?Value $mode): Value
    {
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $mode) {
            $mode = $context->constantStringFromString('a');
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_php_uname'),
            $mode
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function stringOrFalse(Context $context, Value $raw, string $label): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $label.'_fail');
        $okBlock = BasicBlockHelper::append($context, $label.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $label.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function version_compare(
        Context $context,
        JITVariable $ver1,
        JITVariable $ver2,
        ?JITVariable $operator = null
    ): Value {
        if (null !== $operator) {
            $opLit = $operator->compileTimeString ?? null;
            if (null !== $opLit && !VmInfo::isValidVersionCompareOperator($opLit)) {
                return self::emitVersionCompareOperatorValueError($context);
            }
        }

        StringVersionCompare::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_version_compare'),
            JitStringBuiltinArg::lowerRequiredString($context, $ver1, 'version_compare', 0, 'version1'),
            JitStringBuiltinArg::lowerRequiredString($context, $ver2, 'version_compare', 1, 'version2')
        );
        if (null === $operator) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeLong($context, $slot, $raw);

            return $ptr;
        }

        return self::versionCompareWithOperator($context, $raw, $operator);
    }

    private static function emitVersionCompareOperatorValueError(Context $context): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, VmInfo::VERSION_COMPARE_OPERATOR_ERROR);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('abort'));

        return $ptr;
    }

    public static function extension_loaded(Context $context, JITVariable $extension): Value
    {
        StringInfo::ensureLinked($context);
        $loaded = $context->builder->call(
            $context->lookupFunction('__compiler_extension_loaded'),
            JitStringBuiltinArg::lower($context, $extension, 'extension_loaded', 0, 'extension')
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $truthy = $context->builder->icmp(
            Builder::INT_NE,
            $loaded,
            $i32->constInt(0, false)
        );
        JitValueBox::writeBool($context, $slot, $truthy);

        return $ptr;
    }

    public static function get_loaded_extensions(Context $context, Value $zendExtensions): Value
    {
        StringInfo::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $zendFlag = $context->builder->zExt(
            $context->builder->icmp(
                Builder::INT_NE,
                $zendExtensions,
                $context->constantFromBool(false)
            ),
            $i32
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_get_loaded_extensions'),
            $zendFlag
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }

    public static function get_extension_funcs(Context $context, JITVariable $extension): Value
    {
        StringInfo::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_get_extension_funcs'),
            JitStringBuiltinArg::lower($context, $extension, 'get_extension_funcs', 0, 'extension_name')
        );
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'get_extension_funcs_fail');
        $okBlock = BasicBlockHelper::append($context, 'get_extension_funcs_ok');
        $doneBlock = BasicBlockHelper::append($context, 'get_extension_funcs_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function versionCompareWithOperator(
        Context $context,
        Value $compare,
        JITVariable $operator
    ): Value {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, true);
        $one = $i64->constInt(1, true);
        $isLt = $context->builder->icmp(Builder::INT_EQ, $compare, $negOne);
        $isEq = $context->builder->icmp(Builder::INT_EQ, $compare, $zero);
        $isGt = $context->builder->icmp(Builder::INT_EQ, $compare, $one);
        $notGt = $context->builder->icmp(Builder::INT_NE, $compare, $one);
        $notLt = $context->builder->icmp(Builder::INT_NE, $compare, $negOne);
        $notEq = $context->builder->icmp(Builder::INT_NE, $compare, $zero);

        $false = $i64->constInt(0, false);
        $result = $false;
        $matched = $context->constantFromBool(false);
        foreach ([
            ['<', $context->builder->zExt($isLt, $i64)],
            ['lt', $context->builder->zExt($isLt, $i64)],
            ['<=', $context->builder->zExt($notGt, $i64)],
            ['le', $context->builder->zExt($notGt, $i64)],
            ['>', $context->builder->zExt($isGt, $i64)],
            ['gt', $context->builder->zExt($isGt, $i64)],
            ['>=', $context->builder->zExt($notLt, $i64)],
            ['ge', $context->builder->zExt($notLt, $i64)],
            ['==', $context->builder->zExt($isEq, $i64)],
            ['=', $context->builder->zExt($isEq, $i64)],
            ['eq', $context->builder->zExt($isEq, $i64)],
            ['!=', $context->builder->zExt($notEq, $i64)],
            ['<>', $context->builder->zExt($notEq, $i64)],
            ['ne', $context->builder->zExt($notEq, $i64)],
        ] as [$literal, $longVal]) {
            $matches = self::jitStringEqualsLiteral($context, $operator, $literal);
            $matched = $context->builder->or($matched, $matches);
            $result = $context->builder->select($matches, $longVal, $result);
        }

        $validOk = BasicBlockHelper::append($context, 'version_compare_op_ok');
        $validErr = BasicBlockHelper::append($context, 'version_compare_op_err');
        $context->builder->branchIf($matched, $validOk, $validErr);
        $context->builder->positionAtEnd($validErr);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, VmInfo::VERSION_COMPARE_OPERATOR_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($validOk);
        JitValueBox::writeLong($context, $slot, $result);

        return $ptr;
    }

    private static function jitStringEqualsLiteral(
        Context $context,
        JITVariable $string,
        string $literal
    ): Value {
        $strPtr = JitStringArg::lower($context, $string, 'version_compare() operator');
        $map = $context->structFieldMap['__string__'];
        $strData = $context->builder->structGep($strPtr, $map['value']);
        $litGlobal = $context->constantStringFromString($literal);
        $litPtr = $context->builder->load($litGlobal);
        $litData = $context->builder->structGep($litPtr, $map['value']);
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $strData,
            $litData
        );

        return $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
    }
}
