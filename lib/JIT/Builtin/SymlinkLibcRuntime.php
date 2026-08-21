<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc symlink(2) body for __phpc_jit_symlink (#33416).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\SymlinkJitHelper} cannot create links under thin
 * AOT: host \\symlink() re-enters __phpc_jit_symlink (peer unlink #33412 / mkdir #33402 /
 * link NestedJIT leaf #33406). Platform symlink(2) is the justified thin ABI.
 *
 * php-src: ext/standard/link.c — PHP_FUNCTION(symlink) / php_symlink
 */
final class SymlinkLibcRuntime
{
    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('symlink_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i32->constInt(0, false);
        $true = $i1->constInt(1, false);
        $false = $i1->constInt(0, false);

        $target = $fn->getParam(0);
        $link = $fn->getParam(1);
        $fail = $fn->appendBasicBlock('symlink_libc_fail');
        $checkLink = $fn->appendBasicBlock('symlink_libc_check_link');
        $call = $fn->appendBasicBlock('symlink_libc_call');

        $targetNull = $context->builder->icmp(Builder::INT_EQ, $target, $strPtr->constNull());
        $context->builder->branchIf($targetNull, $fail, $checkLink);

        $context->builder->positionAtEnd($checkLink);
        $linkNull = $context->builder->icmp(Builder::INT_EQ, $link, $strPtr->constNull());
        $context->builder->branchIf($linkNull, $fail, $call);

        $context->builder->positionAtEnd($call);
        $t = self::stringData($context, $target);
        $l = self::stringData($context, $link);
        $rc = $context->builder->call($context->lookupFunction('symlink'), $t, $l);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        // getNamedFunction first — leftover addFunction without it mints symlink.1 (#31894 / #32122).
        $probe = $context->module->getNamedFunction('symlink');
        if (null !== $probe) {
            $context->registerFunction('symlink', $probe);

            return;
        }
        try {
            $context->lookupFunction('symlink');
        } catch (\Throwable) {
            $decl = $context->module->addFunction(
                'symlink',
                $context->context->functionType($i32, false, $i8p, $i8p)
            );
            $context->registerFunction('symlink', $decl);
        }
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
