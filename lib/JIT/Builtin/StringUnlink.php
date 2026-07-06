<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for unlink() via UnlinkJitHelper PHP (#15471).
 *
 * Replaces libc unlink(2) LLVM in ext/standard/JitUnlink.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::unlink()}.
 * php-src: ext/standard/filestat.c — php_unlink
 */
final class StringUnlink
{
    private const ABI = '__phpc_jit_unlink';

    private const HELPER_PATH = '/ext/standard/UnlinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\UnlinkJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementLibcBridge($context, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15471');

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('unlink_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#15471');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0)]);
        $bool = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementLibcBridge(Context $context, ?\PHPLLVM\Value\Function_ $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('unlink_libc_entry');
        $context->builder->positionAtEnd($entry);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($fn->getParam(0), $map['value']);
        $ret = $context->builder->call(
            $context->lookupFunction('unlink'),
            $pathPtr
        );
        $zero = $i32->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
        $context->builder->returnValue($ok);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
