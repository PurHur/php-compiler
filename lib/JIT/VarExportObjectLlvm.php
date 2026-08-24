<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringVarExport;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * Thin AOT var_export() for objects (#34506).
 *
 * Peer of {@see JitSerialize::encodePublicObjectProps}. Declaration is early;
 * body is emitted in {@see emitBodyIfPending} after user script lowering so
 * Native get_object_vars sees full property metadata (empty `new stdClass`
 * before a later cast must not bake an empty class-id dispatch).
 *
 * php-src: ext/standard/var.c — php_var_export_ex object branch
 */
final class VarExportObjectLlvm
{
    public const ABI = '__compiler_var_export_object';

    private const BRIDGE_ENTRY = 'var_export_object_bridge_entry';

    public static function declareHelper(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        StringVarExport::ensureFormatHelpersForArrayLlvm($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = $context->module->addFunction(self::ABI, $ft);
        $context->registerFunction(self::ABI, $fn);
    }

    /** Emit body once after user script props are registered (#34506). */
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

        StringVarExport::ensureFormatHelpersForArrayLlvm($context);
        $context->registerFunction(self::ABI, $probe);

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        BasicBlockHelper::scopeLoweringToFunction($context, $probe, self::ABI, static function () use ($context, $probe): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($probe, self::BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(self::encodeBody($context, $probe->getParam(0)));
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    public static function encode(Context $context, Value $valuePtr): Value
    {
        self::declareHelper($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $valuePtr
        );
    }

    private static function encodeBody(Context $context, Value $valuePtr): Value
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
        $i64 = $context->getTypeFromString('int64');
        $props = VarExportArrayLlvm::encode(
            $context,
            $ht,
            $i64->constInt(0, false),
            true
        );

        $stdClass = $context->builder->load($context->constantStringFromString('stdClass'));
        $isStd = JitStringCompare::identical($context, $className, $stdClass);

        $stdForm = JitStringConcat::concat(
            $context,
            $context->builder->load($context->constantStringFromString('(object) ')),
            $props
        );
        $setState = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat(
                        $context,
                        $context->builder->load($context->constantStringFromString('\\')),
                        $className
                    ),
                    $context->builder->load($context->constantStringFromString('::__set_state('))
                ),
                $props
            ),
            $context->builder->load($context->constantStringFromString(')'))
        );

        return $context->builder->select($isStd, $stdForm, $setState);
    }
}
