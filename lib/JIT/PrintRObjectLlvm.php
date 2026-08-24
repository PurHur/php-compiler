<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringPrintR;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * Thin AOT print_r() for objects (#34506).
 *
 * Declaration early; body via {@see emitBodyIfPending} after user lowering
 * (peer {@see VarExportObjectLlvm}).
 *
 * php-src: ext/standard/var.c — zend_print_zval_r object branch
 */
final class PrintRObjectLlvm
{
    public const ABI = '__compiler_print_r_object';

    private const BRIDGE_ENTRY = 'print_r_object_bridge_entry';

    public static function declareHelper(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        StringPrintR::ensureHelpersForArrayLlvm($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr, $i64);
        $fn = $context->module->addFunction(self::ABI, $ft);
        $context->registerFunction(self::ABI, $fn);
    }

    public static function emitBodyIfPending(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null === $probe) {
            return;
        }
        if ($probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        StringPrintR::ensureHelpersForArrayLlvm($context);
        $context->registerFunction(self::ABI, $probe);

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        BasicBlockHelper::scopeLoweringToFunction($context, $probe, self::ABI, static function () use ($context, $probe): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($probe, self::BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                self::encodeBody($context, $probe->getParam(0), $probe->getParam(1))
            );
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    public static function encode(Context $context, Value $valuePtr, Value $level): Value
    {
        self::declareHelper($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $valuePtr,
            $level
        );
    }

    private static function encodeBody(Context $context, Value $valuePtr, Value $level): Value
    {
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
        $className = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );
        $label = JitStringConcat::concat(
            $context,
            $className,
            $context->builder->load($context->constantStringFromString(' Object'))
        );

        return PrintRArrayLlvm::encodeLabeled($context, $ht, $level, $label);
    }
}
