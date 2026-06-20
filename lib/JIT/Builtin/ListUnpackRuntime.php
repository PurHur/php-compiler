<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for list/spread unpack guards via ListUnpackJitHelper PHP (#10221).
 *
 * SSOT: {@see \PHPCompiler\VM\ListUnpackJitHelper}
 */
final class ListUnpackRuntime
{
    private const HELPER_PATH = '/lib/VM/ListUnpackJitHelper.php';

    private const VALUE_BOX_IS_ARRAY = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsArray';

    private const VALUE_BOX_IS_STRING = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsString';

    private const VALUE_BOX_IS_UNPACKABLE = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsListDestructUnpackable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_IS_ARRAY,
        self::VALUE_BOX_IS_STRING,
        self::VALUE_BOX_IS_UNPACKABLE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__list_unpack__valueBoxIsArray');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementValueBoxBridge($context, '__list_unpack__valueBoxIsArray', self::VALUE_BOX_IS_ARRAY, 1);
        self::implementValueBoxBridge($context, '__list_unpack__valueBoxIsString', self::VALUE_BOX_IS_STRING, 1);
        self::implementValueBoxBridge(
            $context,
            '__list_unpack__valueBoxIsListDestructUnpackable',
            self::VALUE_BOX_IS_UNPACKABLE,
            2
        );
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callValueBoxIsArray(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__list_unpack__valueBoxIsArray');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function callValueBoxIsString(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__list_unpack__valueBoxIsString');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function callValueBoxIsListDestructUnpackable(
        Context $context,
        Value $typeByte,
        Value $implementsArrayAccess
    ): Value {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__list_unpack__valueBoxIsListDestructUnpackable');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($implementsArrayAccess, $i1)
        );
    }

    public static function loadValueBoxTypeByte(Context $context, Variable $var): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);

        return $context->builder->load(
            $context->builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            '__list_unpack__valueBoxIsArray',
            '__list_unpack__valueBoxIsString',
            '__list_unpack__valueBoxIsListDestructUnpackable',
        ] as $abiName) {
            $fn = $context->module->getNamedFunction($abiName);
            if (null !== $fn) {
                $context->registerFunction($abiName, $fn);
            }
        }
    }

    private static function implementValueBoxBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $paramTypes = 2 === $paramCount ? [$i8, $i1] : [$i8];
        $ft = $context->context->functionType($i1, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('list_unpack_value_box_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            ...$args
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ListUnpackJitHelper compile (#10221)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ListUnpackJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ListUnpackJitHelper.php parseAndCompile failed (#10221)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10221)');
            }
        }
    }
}
