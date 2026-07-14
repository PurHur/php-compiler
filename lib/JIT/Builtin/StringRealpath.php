<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __phpc_jit_realpath via RealpathJitHelper PHP (#15323).
 *
 * Replaces libc realpath(3)/strlen LLVM in ext/standard/JitRealpath.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::realpath()}.
 * php-src: ext/standard/basic_functions.c — php_realpath
 */
final class StringRealpath
{
    private const ABI = '__phpc_jit_realpath';

    private const HELPER_PATH = '/ext/standard/RealpathJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\RealpathJitHelper::resolveArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, \PHPLLVM\Value $path): \PHPLLVM\Value
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        self::ensureLinked($context);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'realpath_invoke_cont');
        }

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15323');

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('realpath_bridge_entry');
        $failBb = $fn->appendBasicBlock('realpath_bridge_fail');
        $okBb = $fn->appendBasicBlock('realpath_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $isNullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNullPath, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#15323');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path]);
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failResultBb = $fn->appendBasicBlock('realpath_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('realpath_bridge_result_ok');
        $context->builder->branchIf($isNullResult, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($context->builder->load($context->constantStringFromString('')));

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
