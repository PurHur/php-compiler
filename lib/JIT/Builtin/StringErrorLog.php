<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_error_log via ErrorLogJitHelper PHP (#9253, #24094).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * Replaces fprintf(stderr) LLVM; SSOT {@see \PHPCompiler\ext\standard\VmErrorLog}.
 * php-src: ext/standard/basic_functions.c — _php_error_log
 */
final class StringErrorLog
{
    private const HELPER_PATH = '/ext/standard/ErrorLogJitHelper.php';

    private const LOG_STDERR_HELPER = 'PHPCompiler\\ext\\standard\\ErrorLogJitHelper::logStderr';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG_STDERR_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $abiName = '__compiler_error_log';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('error_log_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::LOG_STDERR_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24094');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24094'
        );
    }
}
