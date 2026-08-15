<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * htmlspecialchars_decode() — decode HTML entities (subset of PHP; issue #2454).
 *
 * VM: {@see VmString::htmlspecialchars_decode()}; JIT/AOT: {@see HtmlspecialcharsDecodeJitHelper} (#14820).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30720; php-src ext/standard/html.c).
 */
final class htmlspecialchars_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('htmlspecialchars_decode');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30720; ext/standard/html.stub.php).
        $this->requireArgCountRange($frame, 'htmlspecialchars_decode', 1, 2);
        $argc = \count($frame->calledArgs);
        $string = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if ($argc >= 2) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31212).
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'htmlspecialchars_decode',
                2,
                'flags'
            );
        }
        $frame->returnVar->string(VmString::htmlspecialchars_decode($string, $flags));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError under AOT try/catch (#30720).
        if (!$this->requireArgCountRangeJit($context, $args, 'htmlspecialchars_decode', 1, 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);

        // Fold proven compile-time strings: KIND_VALUE immediates and TYPE_STRING
        // stack slots (literal args / assigned locals keep KIND_VARIABLE with
        // compileTimeString) — peer htmlspecialchars (#25345) / html_entity_decode.
        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            if (null !== $maybeLiteral
                && (JITVariable::KIND_VALUE === $args[0]->kind
                    || JITVariable::TYPE_STRING === $args[0]->type)) {
                $literal = $maybeLiteral;
            }
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $flagsKnown = $argc < 2;
        if ($argc >= 2) {
            // Soft-null outside strict_types; strict → TypeError (#31212).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'htmlspecialchars_decode', 2, 'flags');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'htmlspecialchars_decode_null_flags_te_cont');

                return $context->getTypeFromString('__string__*')->constNull();
            }
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $flagsKnown = false;
            } elseif (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
            } else {
                $ct = $args[1]->compileTimeLong ?? null;
                if (null !== $ct) {
                    $flags = (int) $ct;
                    $flagsKnown = true;
                }
            }
        }
        if (null !== $literal && $flagsKnown) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars_decode($literal, $flags)
                )
            );
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'htmlspecialchars_decode', 0, 'string');
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $i64->constInt($flags, false);
        if ($argc >= 2 && !$flagsKnown) {
            $flagsVal = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'htmlspecialchars_decode',
                2,
                'flags'
            );
        } elseif ($argc >= 2 && null === ($args[1]->compileTimeLong ?? null)
            && JITVariable::TYPE_NULL !== $args[1]->type
            && !($args[1]->isNullConstant ?? false)) {
            $flagsVal = $context->helper->loadValue($args[1]);
        }

        return JitHtmlspecialcharsDecode::decode($context, $str, $flagsVal);
    }

    /** Soft-null — coerce+deprecate on forward profile (#21180, ext/standard/html.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'htmlspecialchars_decode', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'htmlspecialchars_decode',
            $argIndex,
            $paramName
        );
    }
}
