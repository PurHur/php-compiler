<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc realpath(3) body for __phpc_jit_realpath (#33432).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\RealpathJitHelper} returns empty under thin
 * AOT (DirectoryIterator already bypassed it in #33287). Platform realpath(3) is the
 * justified thin ABI — peer SysGetTempDirRuntime NestedJIT leaf (#29433).
 *
 * php-src: ext/standard/basic_functions.c — php_realpath / PHP_FUNCTION(realpath)
 */
final class RealpathLibcRuntime
{
    private const PATH_BUF = 4096;

    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);
        LibcExtern::ensureStrlenDecl($context);

        $entry = $fn->appendBasicBlock('realpath_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $fail = $fn->appendBasicBlock('realpath_libc_fail');
        $prep = $fn->appendBasicBlock('realpath_libc_prep');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $prep);

        $context->builder->positionAtEnd($prep);
        $pathCstr = $context->builder->pointerCast(self::stringData($context, $path), $i8p);
        // php-src: empty path resolves as "." (realpath("")).
        $first = $context->builder->load($pathCstr);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(0, false));
        $dot = $context->builder->pointerCast($context->constantFromString('.'), $i8p);
        $usePath = $context->builder->select($isEmpty, $dot, $pathCstr);

        $buf = $context->builder->alloca($i8->arrayType(self::PATH_BUF));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $real = $context->builder->call($context->lookupFunction('realpath'), $usePath, $bufPtr);
        $ok = $context->builder->icmp(Builder::INT_NE, $real, $i8p->constNull());
        $okBb = $fn->appendBasicBlock('realpath_libc_ok');
        $context->builder->branchIf($ok, $okBb, $fail);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($bufPtr, $charPtr)
        );
        $context->builder->returnValue($str);

        $context->builder->positionAtEnd($fail);
        // Empty string is falsy under PHP == (matches prior NestedJIT fail contract).
        $context->builder->returnValue($context->builder->load($context->constantStringFromString('')));
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');

        // getNamedFunction first — leftover addFunction without it mints realpath.1 (#31894 / #32122).
        $probe = $context->module->getNamedFunction('realpath');
        if (null !== $probe) {
            $context->registerFunction('realpath', $probe);

            return;
        }
        try {
            $context->lookupFunction('realpath');
        } catch (\Throwable) {
            $decl = $context->module->addFunction(
                'realpath',
                $context->context->functionType($i8p, false, $i8p, $i8p)
            );
            $context->registerFunction('realpath', $decl);
        }
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
