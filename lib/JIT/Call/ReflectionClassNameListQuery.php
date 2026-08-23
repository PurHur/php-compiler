<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassNameListRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::{getInterfaceNames,getTraitNames} — JIT/AOT (#34110).
 *
 * Thin AOT previously had no proxies; ExternalMethod → NULL.
 * php-src: zim_ReflectionClass_getInterfaceNames / getTraitNames
 */
final class ReflectionClassNameListQuery implements Call
{
    /** @var array<string, string> */
    private const METHOD = [
        'interfacenames' => 'getInterfaceNames',
        'traitnames' => 'getTraitNames',
    ];

    private string $kindLc;

    public function __construct(string $kind)
    {
        $kindLc = strtolower($kind);
        if (!isset(self::METHOD[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass name-list query: '.$kind);
        }
        $this->kindLc = $kindLc;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $method = self::METHOD[$this->kindLc];
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::'.$method,
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.$this->kindLc.'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        return ReflectionClassNameListRuntime::emit(
            $context,
            $this->kindLc,
            $cstr,
            $len
        );
    }
}
