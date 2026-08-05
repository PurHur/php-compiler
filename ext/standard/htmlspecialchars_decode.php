<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * htmlspecialchars_decode() — decode HTML entities (subset of PHP; issue #2454).
 *
 * VM: {@see VmString::htmlspecialchars_decode()}; JIT/AOT: {@see HtmlspecialcharsDecodeJitHelper} (#14820).
 */
final class htmlspecialchars_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('htmlspecialchars_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('htmlspecialchars_decode() requires one or two arguments in this compiler build');
        }
        $string = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if ($argc >= 2) {
            $flags = VmMath::parseZParamLongBuiltinArg(
                $frame->calledArgs[1],
                'htmlspecialchars_decode',
                2,
                'flags',
                $frame
            );
        }
        $frame->returnVar->string(VmString::htmlspecialchars_decode($string, $flags));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('htmlspecialchars_decode() requires one or two arguments in this compiler build');
        }

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
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
            }
            $ct = $args[1]->compileTimeLong ?? null;
            if (null !== $ct) {
                $flags = (int) $ct;
                $flagsKnown = true;
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
        if ($argc >= 2 && null === ($args[1]->compileTimeLong ?? null)) {
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
