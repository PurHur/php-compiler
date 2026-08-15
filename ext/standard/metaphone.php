<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringMetaphone;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * metaphone() — phonetic string encoding (subset of PHP; issue #2423).
 *
 * VM: {@see VmString::metaphone()}; JIT/AOT: {@see StringMetaphone} + {@see MetaphoneJitHelper}.
 */
final class metaphone extends Internal
{
    public function __construct()
    {
        parent::__construct('metaphone');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'metaphone', 1, 2);
        $argc = \count($frame->calledArgs);
        $string = self::vmStringArg($frame, 0, 'string');
        $maxPhonemes = 0;
        if ($argc >= 2) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31230).
            $maxPhonemes = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'metaphone',
                2,
                'max_phonemes'
            );
            // Range check is SSOT in VmMetaphone::encode() (php-src string.c / #29304).
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::metaphone($string, $maxPhonemes));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'metaphone', 1, 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $maxPhonemes = $i64->constInt(0, false);
        if ($argc >= 2) {
            // Soft-null outside strict_types; strict → TypeError (#31230).
            // Early return after compile-time null TypeError — open a dead insert block so the
            // call site can lower a discarded return without mid-block terminator (AOT verify;
            // peer dirname #31210 / intval #31227 / settype #30506).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'metaphone', 2, 'max_phonemes');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'metaphone_null_max_phonemes_te_cont');

                return $context->getTypeFromString('__string__*')->constNull();
            }
            // Z_PARAM_LONG with caller strict_types parity (#31230).
            $maxPhonemes = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'metaphone',
                2,
                'max_phonemes'
            );
        }

        $input = self::jitStringArg($context, $args[0], 1, 'string');
        StringMetaphone::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_metaphone'),
            $input,
            $maxPhonemes
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'metaphone', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190; reverts #19243 TypeError).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'metaphone',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'metaphone',
                $argNumber - 1,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'metaphone',
            $argNumber - 1,
            $paramName
        );
    }
}
