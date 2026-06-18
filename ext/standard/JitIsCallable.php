<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VariableFunctionCallHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for is_callable() (issue #3132). */
final class JitIsCallable
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('is_callable() expects at least one argument');
        }
        $callback = $args[0];
        $syntaxOnly = false;
        $nameOut = $args[2] ?? null;

        if (null !== ClosureHelper::resolveCall($context, $callback)) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameLiteral($context, '{closure}', $nameOut);
            }

            return $context->constantFromInteger(1, 'int1');
        }

        $literal = JitStringArg::compileTimeLiteral($callback);
        if (null !== $literal) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameLiteral($context, $literal, $nameOut);
            }

            return self::checkCompileTimeString($context, $literal, $syntaxOnly);
        }

        if (
            JITVariable::TYPE_STRING === $callback->type
            || JITVariable::TYPE_VALUE === $callback->type
        ) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameFromVariable($context, $callback, $nameOut);
            }

            return self::checkRuntimeString($context, $callback);
        }

        if (
            JITVariable::TYPE_OBJECT === $callback->type
            || (JITVariable::TYPE_VALUE === $callback->type && [] !== ClosureHelper::closureCandidates($context))
        ) {
            $candidates = ClosureHelper::closureCandidates($context);
            if ([] !== $candidates) {
                if (null !== $nameOut) {
                    self::jitWriteCallableNameLiteral($context, '{closure}', $nameOut);
                }

                return $context->constantFromInteger(1, 'int1');
            }
        }

        if (null !== $nameOut && JITVariable::TYPE_NULL === $callback->type) {
            self::jitWriteCallableNameLiteral($context, '', $nameOut);
        }

        return $context->constantFromInteger(0, 'int1');
    }

    private static function jitWriteCallableNameLiteral(Context $context, string $name, JITVariable $nameOut): void
    {
        $outPtr = JitValueBox::valuePtrFromVariable($context, $nameOut);
        $str = $context->builder->load($context->constantStringFromString($name));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
    }

    private static function jitWriteCallableNameFromVariable(Context $context, JITVariable $source, JITVariable $nameOut): void
    {
        $outPtr = JitValueBox::valuePtrFromVariable($context, $nameOut);
        $str = JitStringArg::lower($context, $source, 'is_callable() callback');
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
    }

    private static function checkCompileTimeString(Context $context, string $name, bool $syntaxOnly): Value
    {
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $valid = '' !== $class && '' !== $method;
            if ($valid && !$syntaxOnly) {
                $valid = $context->classIsRegistered(strtolower(ltrim($class, '\\')));
            }

            return $context->constantFromInteger($valid ? 1 : 0, 'int1');
        }
        $valid = (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name);
        if ($valid && !$syntaxOnly) {
            $valid = $context->functionIsRegistered(strtolower($name));
        }

        return $context->constantFromInteger($valid ? 1 : 0, 'int1');
    }

    private static function checkRuntimeString(Context $context, JITVariable $callback): Value
    {
        $hints = self::hintedFunctionNames($context);
        if ([] === $hints) {
            return $context->constantFromInteger(0, 'int1');
        }
        $nameStr = JitStringArg::lower($context, $callback, 'is_callable() var');
        $candidates = VariableFunctionCallHelper::dispatchCandidates($context, $hints);
        if ([] === $candidates) {
            return $context->constantFromInteger(0, 'int1');
        }
        if (1 === \count($candidates)) {
            $fnName = array_key_first($candidates);
            assert(is_string($fnName));
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $nameStr,
                $literalStr
            );

            return $isMatch;
        }
        $i1 = $context->getTypeFromString('int1');
        $result = $i1->constInt(0, false);
        foreach ($candidates as $fnName => $_proxy) {
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $nameStr, $literalStr);
            $result = $context->builder->or($result, $isMatch);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function hintedFunctionNames(Context $context): array
    {
        $block = $context->jitCurrentBlock;
        if (null === $block) {
            return [];
        }

        return array_values(array_unique(array_merge(
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block)
        )));
    }
}
