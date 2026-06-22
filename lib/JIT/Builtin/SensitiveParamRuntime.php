<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SensitiveParamJitHelper;
use PHPLLVM\Value;

/**
 * JIT/AOT link for #[\SensitiveParameter] debug_backtrace redaction via SensitiveParamJitHelper PHP (#10394).
 *
 * SSOT: {@see \PHPCompiler\VM\SensitiveParamSupport}, {@see SensitiveParamJitHelper}
 */
final class SensitiveParamRuntime
{
    private const HELPER_PATH = '/VM/SensitiveParamJitHelper.php';

    private const SHOULD_IGNORE_ARGS = 'PHPCompiler\\VM\\SensitiveParamJitHelper::shouldIgnoreBacktraceArgs';

    private const ABI_SHOULD_IGNORE_ARGS = '__sensitive_param__shouldIgnoreBacktraceArgs';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SHOULD_IGNORE_ARGS,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (null !== self::probeLinked($context, self::ABI_SHOULD_IGNORE_ARGS)) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SHOULD_IGNORE_ARGS,
            'sensitive_param_ignore_args_bridge_entry',
            [$i64],
            $i1,
            self::SHOULD_IGNORE_ARGS,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10394'
        );
        $context->builder->clearInsertionPosition();
    }

    /** Empty SensitiveParameterValue marker object (Zend SensitiveParameterValue). */
    public static function createMarker(Context $context): Variable
    {
        $classId = $context->type->object->lookup(SensitiveParamJitHelper::markerClassName());
        $obj = $context->type->object->allocate($classId);

        return new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
    }

    public static function ignoreArgsBit(Context $context, ?Variable $optionsArg): Value
    {
        self::ensureLinked($context);
        $i1 = $context->getTypeFromString('int1');
        if (null === $optionsArg) {
            return $i1->constInt(0, false);
        }

        $options = self::readOptionsLong($context, $optionsArg);
        $fn = $context->lookupFunction(self::ABI_SHOULD_IGNORE_ARGS);

        return $context->builder->call($fn, $options);
    }

    private static function readOptionsLong(Context $context, Variable $optionsArg): Value
    {
        if (Variable::TYPE_NATIVE_LONG === $optionsArg->type) {
            return $context->helper->loadValue($optionsArg);
        }
        if (Variable::TYPE_VALUE === $optionsArg->type) {
            $valuePtr = Variable::KIND_VARIABLE === $optionsArg->kind
                ? JitValueBox::pointer($context, $optionsArg->value)
                : $optionsArg->value;

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }

        return $context->constantFromInteger(0, 'int64');
    }

    private static function probeLinked(Context $context, string $abiName): ?\PHPLLVM\Value\Function_
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return $probe;
        }

        return null;
    }
}
