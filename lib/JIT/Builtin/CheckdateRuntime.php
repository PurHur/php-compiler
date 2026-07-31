<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_checkdate via VmCheckdate PHP (#9242, #26196).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Ctype #22626 / apache_note #26120).
 * Replaces duplicated Gregorian calendar LLVM. SSOT: {@see \PHPCompiler\ext\standard\VmCheckdate}.
 * php-src: ext/standard/datetime.c PHP_FUNCTION(checkdate)
 */
final class CheckdateRuntime
{
    private const HELPER_PATH = '/ext/standard/VmCheckdate.php';

    private const CHECKDATE_HELPER = 'PHPCompiler\\ext\\standard\\VmCheckdate::validate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHECKDATE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_checkdate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_checkdate', $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_checkdate', $ft);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('checkdate_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_checkdate', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CHECKDATE_HELPER, '#26196');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26196'
        );
    }
}
