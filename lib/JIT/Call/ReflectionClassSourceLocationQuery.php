<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassSourceLocationRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::{getStartLine,getEndLine,getDocComment} — JIT/AOT (#34106).
 *
 * Thin AOT previously had no proxies; ExternalMethod → NULL.
 * Peer {@see ReflectionClassGetFileName} (#34096); VM #7358.
 *
 * php-src: zim_ReflectionClass_getStartLine / getEndLine / getDocComment
 */
final class ReflectionClassSourceLocationQuery implements Call
{
    /**
     * @param 'getStartLine'|'getEndLine'|'getDocComment' $method
     */
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $display = 'ReflectionClass::'.$this->method;
        $userArgCount = \count($args) - 1;
        // php-src: 0 args; $args[0] is $this (#30888)
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($display, 0, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $field = match ($this->method) {
            'getStartLine' => 'startLine',
            'getEndLine' => 'endLine',
            'getDocComment' => 'docComment',
            default => throw new \InvalidArgumentException(
                'ReflectionClassSourceLocationQuery: unknown method '.$this->method
            ),
        };

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        return ReflectionClassSourceLocationRuntime::emit($context, $cstr, $len, $field);
    }
}
