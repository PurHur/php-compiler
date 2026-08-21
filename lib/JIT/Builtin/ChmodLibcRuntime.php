<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc chmod(2) body for __phpc_jit_chmod (#33418).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\ChmodJitHelper} cannot set modes under thin
 * AOT: host \\chmod() re-enters __phpc_jit_chmod (peer unlink #33412 / mkdir #33402 / touch #28995).
 * Platform chmod(2) is the justified thin ABI (php-src VCWD_CHMOD / php_chmod).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chmod) / php_chmod
 */
final class ChmodLibcRuntime
{
    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('chmod_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i32->constInt(0, false);
        $true = $i1->constInt(1, false);
        $false = $i1->constInt(0, false);

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $fail = $fn->appendBasicBlock('chmod_libc_fail');
        $call = $fn->appendBasicBlock('chmod_libc_call');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $call);

        $context->builder->positionAtEnd($call);
        $p = self::stringData($context, $path);
        // Match VmFsDirPure::chmod — mask to permission bits before chmod(2).
        $modeMasked = $context->builder->and($mode, $i32->constInt(07777, false));
        $rc = $context->builder->call($context->lookupFunction('chmod'), $p, $modeMasked);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        // getNamedFunction first — leftover addFunction without it mints chmod.1 (#31894 / #32122).
        $probe = $context->module->getNamedFunction('chmod');
        if (null !== $probe) {
            $context->registerFunction('chmod', $probe);

            return;
        }
        try {
            $context->lookupFunction('chmod');
        } catch (\Throwable) {
            $decl = $context->module->addFunction(
                'chmod',
                $context->context->functionType($i32, false, $i8p, $i32)
            );
            $context->registerFunction('chmod', $decl);
        }
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
