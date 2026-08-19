<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_iconv via IconvJitHelper PHP (#9345, #25570).
 *
 * Type always-on leftover dropped (#32482): declareFunction uses getNamedFunction first
 * so a drifted ABI cannot mint __compiler_iconv.1 (#31894 / #32122).
 * Replaces ~750-line CharsetEngine LLVM monolith. SSOT: {@see \PHPCompiler\ext\iconv\VmIconv}.
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv)
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MimeContentTypeRuntime #25544).
 */
final class IconvRuntime
{
    private const HELPER_PATH = '/ext/iconv/IconvJitHelper.php';

    private const CONVERT_HELPER = 'PHPCompiler\\ext\\iconv\\IconvJitHelper::convert';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_iconv',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_iconv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_iconv', self::implementIconvBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $strPtr = $context->getTypeFromString('__string__*');

        // getNamedFunction first — leftover Type always-on addFunction without it
        // minted __compiler_iconv.1 on ABI drift (#32482 / #32122).
        return $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
        );
    }

    private static function implementIconvBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('iconv_bridge_entry');
        $fail = $fn->appendBasicBlock('iconv_bridge_fail');
        $body = $fn->appendBasicBlock('iconv_bridge_body');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $input = $fn->getParam(2);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $strPtr->constNull()),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $to, $strPtr->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $input, $strPtr->constNull())
            )
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$from, $to, $input]
        );
        $result = JitNestedHelperCoerce::coerceBridgeResult($context, $resultRaw, $strPtr);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_HELPER, '#25570');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25570'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after IconvRuntime bridge (#25570)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
