<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\InOperatorJitHelper;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for `$needle in $haystack` value-box guard via InOperatorJitHelper PHP (#10172).
 *
 * SSOT: {@see \PHPCompiler\VM\InOperator}, {@see \PHPCompiler\VM\InOperatorJitHelper}
 */
final class InOperatorRuntime
{
    private const HELPER_PATH = '/lib/VM/InOperatorJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\InOperatorJitHelper::valueBoxHaystackIsArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__in_op__valueBoxHaystackIsArray');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__in_op__valueBoxHaystackIsArray', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementValueBoxBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callValueBoxHaystackIsArray(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__in_op__valueBoxHaystackIsArray');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function emitGuardHaystackIsArray(
        Context $context,
        Variable $needle,
        Variable $haystack
    ): void {
        if (ArrayBuiltinHelper::isNativeArray($haystack->type)
            || Variable::TYPE_HASHTABLE === $haystack->type
        ) {
            return;
        }
        if (Variable::TYPE_VALUE === $haystack->type || JitValueBox::isValueOperand($haystack)) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $haystack);
            $map = $context->structFieldMap['__value__'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($valuePtr, $map['type'])
            );
            self::ensureLinked($context);
            $isArray = self::callValueBoxHaystackIsArray($context, $typeByte);
            $okBlock = BasicBlockHelper::append($context, 'type_in_haystack_ok');
            $failBlock = BasicBlockHelper::append($context, 'type_in_haystack_fail');
            $context->builder->branchIf($isArray, $okBlock, $failBlock);
            $context->builder->positionAtEnd($failBlock);
            self::emitHaystackTypeError($context, $needle, $haystack);
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        self::emitHaystackTypeError($context, $needle, $haystack);
    }

    private static function emitHaystackTypeError(Context $context, Variable $needle, Variable $haystack): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'Unsupported operand types: '
            .self::operandLabel($needle)
            .' in '
            .self::operandLabel($haystack)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function operandLabel(Variable $var): string
    {
        if (Variable::TYPE_VALUE === $var->type || JitValueBox::isValueOperand($var)) {
            return 'mixed';
        }

        return InOperatorJitHelper::jitOperandLabel(
            $var->type,
            ArrayBuiltinHelper::isNativeArray($var->type)
        );
    }

    private static function implementValueBoxBridge(Context $context): void
    {
        $abiName = '__in_op__valueBoxHaystackIsArray';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('in_op_value_box_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::VALUE_BOX_HELPER),
            $fn->getParam(0)
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
            throw new \LogicException($logical.' missing after InOperatorJitHelper compile (#10172)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'InOperatorJitHelper.php');
            if (null === $block) {
                throw new \LogicException('InOperatorJitHelper.php parseAndCompile failed (#10172)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#10172)');
            }
        }
    }
}
