<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomXPathEvaluateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMXPath::evaluate() (#18526). */
final class JitDomXPathEvaluate
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMXPath::evaluate() expects receiver and expression');
        }

        // Compile-time null under strict_types: raise TypeError before user-script (#30041).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMXPath::evaluate(): Argument #1 ($expression) must be of type string, null given'
            );
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return JitValueBox::normalizeValuePtr($context, $ptr);
        }

        if (JitDomXPathEvaluateUserScript::shouldUse($context)) {
            $us = JitDomXPathEvaluateUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        $exprLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $expr = null !== $exprLit ? trim($exprLit) : null;
        // string()/name()/local-name()/namespace-uri()/php:function*() before bool —
        // `=` inside [@attr=…] is not a comparison (#21148, #21238, #27575).
        if (null !== $expr && preg_match('~^(string|name|local-name|namespace-uri|php:function(?:String)?)\(~i', $expr)) {
            DomXPathEvaluateRuntime::ensureStringLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_string_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_STRING),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }
        // Bool: boolean()/not()/comparisons; double: count/sum/number/arithmetic (#20280).
        if (null !== $expr && self::isBoolEvaluateExpr($expr)) {
            DomXPathEvaluateRuntime::ensureBoolLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_bool_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_BOOL),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }
        if (null !== $expr && self::isDoubleEvaluateExpr($expr)) {
            DomXPathEvaluateRuntime::ensureDoubleLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_double_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_DOUBLE),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }

        throw new \LogicException('DOMXPath::evaluate() user-script AOT requires boolean/not/compare, count/sum/number/arithmetic, string/name()/local-name()/namespace-uri(), or php:function*() literal');
    }

    private static function isBoolEvaluateExpr(string $expr): bool
    {
        if (preg_match('~^(true|false|boolean\(|not\()~i', $expr)) {
            return true;
        }
        // Top-level comparison only — skip [=<>] inside () / [] (#21148).
        $depth = 0;
        $quote = null;
        $len = \strlen($expr);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $expr[$i];
            if (null !== $quote) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
                --$depth;
                continue;
            }
            if (0 === $depth && ('=' === $ch || '<' === $ch || '>' === $ch)) {
                return true;
            }
        }

        return false;
    }

    private static function isDoubleEvaluateExpr(string $expr): bool
    {
        if (preg_match('~^(number|count|sum)\(~i', $expr)) {
            return true;
        }
        if (1 === preg_match('~^[+-]?(?:\d+\.?\d*|\.\d+)$~', $expr)) {
            return true;
        }
        if (1 === preg_match('~[=<>]~', $expr)) {
            return false;
        }

        return str_contains($expr, '+')
            || 1 === preg_match('~(?<=[\d).])\s*-|^\d+\s*-|\s+-\s+~', $expr)
            || str_contains($expr, '*')
            || 1 === preg_match('~\bdiv\b|\bmod\b~i', $expr);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMXPath::evaluate() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_STR + caller strict_types — null must TypeError (#30041).
        return JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $arg,
            'DOMXPath::evaluate',
            0,
            'expression'
        );
    }
}
