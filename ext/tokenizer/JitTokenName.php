<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for token_name() (#3171, #7254). */
final class JitTokenName
{
    public static function lower(Context $context, JITVariable $arg): Value
    {
        $name = self::resolveCompileTimeName($context, $arg);

        return $context->builder->load($context->constantStringFromString($name));
    }

    private static function resolveCompileTimeName(Context $context, JITVariable $arg): string
    {
        if (null !== $arg->compileTimeConstantName) {
            $constants = TokenConstants::registeredConstants();
            if (isset($constants[$arg->compileTimeConstantName])) {
                return $arg->compileTimeConstantName;
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                $id = (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
                $name = TokenConstants::nameForId($id);
                if (null !== $name) {
                    return $name;
                }

                return 'UNKNOWN';
            }
        }

        throw new \LogicException(
            'token_name() argument must be a compile-time T_* constant in this compiler build'
        );
    }
}
