<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Builtin\StringPhpinfoRuntime;
use PHPCompiler\JIT\Builtin\StringVersionCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for phpversion/php_uname/php_sapi_name/version introspection (#3174, #3204, #6124). */
final class JitInfo
{
    public static function phpversion(Context $context, ?JITVariable $extension): Value
    {
        if ($context->isUserScriptAot()) {
            $folded = self::tryFoldPhpversionUserScript($context, $extension);
            if (null !== $folded) {
                return $folded;
            }
        }
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $extArg = $strPtr->constNull();
        if (null !== $extension) {
            $extArg = JitStringBuiltinArg::lowerNullableString(
                $context,
                $extension,
                'phpversion',
                0,
                'extension'
            );
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_phpversion'),
            $extArg
        );

        return self::stringOrFalse($context, $raw, 'phpversion');
    }

    public static function php_sapi_name(Context $context): Value
    {
        StringInfo::ensurePhpSapiNameLinked($context);
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
        if ($context->isUserScriptAot() && null === $mode) {
            return self::emitUserScriptStringLiteral($context, InfoJitHelper::php_uname(null));
        }
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $mode) {
            $mode = $context->constantStringFromString('a');
        }
        // Compile-time PROFILE picks NestedJIT-safe strict entry (no getenv in helper, #28136).
        $symbol = VmUnamePure::requiresStrictModeValidation()
            ? '__compiler_php_uname_strict'
            : '__compiler_php_uname';
        $raw = $context->builder->call(
            $context->lookupFunction($symbol),
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

        $folded = self::tryFoldVersionCompare($context, $ver1, $ver2, $operator);
        if (null !== $folded) {
            return $folded;
        }

        StringVersionCompare::ensureLinked($context);
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21556, reverts #20254 TypeError).
        $ver1Str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $ver1,
            'version_compare',
            0,
            'version1'
        );
        $ver2Str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $ver2,
            'version_compare',
            1,
            'version2'
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_version_compare'),
            $ver1Str,
            $ver2Str
        );
        if (null === $operator) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeLong($context, $slot, $raw);

            return $ptr;
        }

        return self::versionCompareWithOperator($context, $raw, $operator);
    }

    /**
     * Fold version_compare when version strings (and optional operator) are compile-time
     * literals — uses VmInfo (full canonicalize) so NestedJIT thin-AOT need not (#26866).
     */
    private static function tryFoldVersionCompare(
        Context $context,
        JITVariable $ver1,
        JITVariable $ver2,
        ?JITVariable $operator
    ): ?Value {
        $v1 = $ver1->compileTimeString ?? JitStringArg::compileTimeLiteral($ver1);
        $v2 = $ver2->compileTimeString ?? JitStringArg::compileTimeLiteral($ver2);
        if (null === $v1 || null === $v2) {
            return null;
        }
        $opLit = null;
        if (null !== $operator) {
            if (JITVariable::TYPE_NULL === $operator->type || ($operator->isNullConstant ?? false)) {
                $opLit = null;
            } else {
                $opLit = $operator->compileTimeString ?? JitStringArg::compileTimeLiteral($operator);
                if (null === $opLit) {
                    return null;
                }
            }
        }
        $result = VmInfo::version_compare($v1, $v2, $opLit);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (\is_bool($result)) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool($result));
        } else {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($result, true)
            );
        }

        return $ptr;
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
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21281, ext/standard/info.c).
        $nullExt = JITVariable::TYPE_NULL === $extension->type || ($extension->isNullConstant ?? false);
        $extStr = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $extension,
            'extension_loaded',
            0,
            'extension'
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if ($nullExt && !$context->callerStrictTypes) {
            // Empty extension name is never loaded — skip C runtime after soft-null (#21281).
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        $loaded = $context->builder->call(
            $context->lookupFunction('__compiler_extension_loaded'),
            $extStr
        );
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
        // Z_PARAM_STR — null TypeError on PROFILE=8.4 (#20254, ext/standard/info.c).
        $nullExt = JITVariable::TYPE_NULL === $extension->type || ($extension->isNullConstant ?? false);
        $extStr = JitStringBuiltinArg::lowerZparamStr($context, $extension, 'get_extension_funcs', 0, 'extension');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (
            $nullExt
            && (
                $context->callerStrictTypes
                || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile()
            )
        ) {
            // lowerZparamStr already emitted TypeError+abort; skip runtime call (#20254).
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_get_extension_funcs'),
            $extStr
        );
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $htPtr->constNull());

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

        // php-src returns bool for the 3-arg form — writeBool (not writeLong) (#26866).
        $result = $context->constantFromBool(false);
        $matched = $context->constantFromBool(false);
        foreach ([
            ['<', $isLt],
            ['lt', $isLt],
            ['<=', $notGt],
            ['le', $notGt],
            ['>', $isGt],
            ['gt', $isGt],
            ['>=', $notLt],
            ['ge', $notLt],
            ['==', $isEq],
            ['=', $isEq],
            ['eq', $isEq],
            ['!=', $notEq],
            ['<>', $notEq],
            ['ne', $notEq],
        ] as [$literal, $boolVal]) {
            $matches = self::jitStringEqualsLiteral($context, $operator, $literal);
            $matched = $context->builder->or($matched, $matches);
            $result = $context->builder->select($matches, $boolVal, $result);
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
        JitValueBox::writeBool($context, $slot, $result);

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
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
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

    /**
     * User-script AOT: fold phpversion() when extension is omitted or a compile-time literal (#21359).
     * Avoids broken nested InfoJitHelper::phpversionArgv bridge at runtime (re-#13803).
     */
    private static function tryFoldPhpversionUserScript(Context $context, ?JITVariable $extension): ?Value
    {
        if (null === $extension) {
            return self::emitUserScriptStringLiteral($context, CompilerVersion::reportedPhpVersion());
        }
        if (JITVariable::TYPE_NULL === $extension->type || ($extension->isNullConstant ?? false)) {
            return self::emitUserScriptFalse($context);
        }
        $lit = $extension->compileTimeString ?? JitStringArg::compileTimeLiteral($extension);
        if (null === $lit) {
            return null;
        }
        $version = InfoJitHelper::phpversionArgv('' === $lit ? null : $lit);
        if (null === $version) {
            return self::emitUserScriptFalse($context);
        }

        return self::emitUserScriptStringLiteral($context, $version);
    }

    /** @internal user-script AOT string results without __compiler_php_uname (#21359) */
    public static function emitUserScriptStringLiteral(Context $context, string $text): Value
    {
        StringInfo::ensureLinked($context);
        $owned = self::initOwnedStringLiteral($context, $text);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $separated = $context->builder->call($context->lookupFunction('__string__separate'), $owned);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $separated);

        return $ptr;
    }

    private static function emitUserScriptFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    private static function initOwnedStringLiteral(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
