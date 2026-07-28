<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;

/**
 * Register NestedJIT proxy for thin-AOT Closure invoke via __closure_target (#24156).
 */
final class NestedClosureInvokeLlvm
{
    public const PROXY = 'phpcompiler\\ext\\standard\\vmclosurecall::invokevariable';

    public static function ensureLinked(Context $context): void
    {
        if (isset($context->functionProxies[self::PROXY])
            && !($context->functionProxies[self::PROXY] instanceof Call\ExternalMethod)
        ) {
            return;
        }
        $context->functionProxies[self::PROXY] = new NestedClosureInvoke();
        $context->functionReturnType[self::PROXY] = '__value__*';
        $id = $context->type->object->lookup('PHPCompiler\\ext\\standard\\VmClosureCall');
        $context->type->object->defineMethodVisibility(
            $id,
            'invokevariable',
            \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
        );
    }
}
