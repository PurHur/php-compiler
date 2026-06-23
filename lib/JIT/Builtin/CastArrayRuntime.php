<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for (array) cast bool branch via CastJitHelper PHP (#10046, #10244).
 *
 * php-src: Zend/zend_operators.c — convert_to_array
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastArrayRuntime
{
    private const HELPER_PATH = '/VM/CastJitHelper.php';

    private const BOOL_EMPTY_HELPER = 'PHPCompiler\\VM\\CastJitHelper::boolYieldsEmptyArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BOOL_EMPTY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__cast__boolYieldsEmptyArray');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__cast__boolYieldsEmptyArray', $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            '__cast__boolYieldsEmptyArray',
            'cast_bool_empty_bridge_entry',
            [$i1],
            $i1,
            self::BOOL_EMPTY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10244'
        );
        $context->builder->clearInsertionPosition();
    }

    public static function callBoolYieldsEmptyArray(Context $context, Value $boolI1): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__cast__boolYieldsEmptyArray');
        $i1 = $context->getTypeFromString('int1');
        $boolArg = $context->builder->trunc($boolI1, $i1);

        return $context->builder->call($fn, $boolArg);
    }
}
