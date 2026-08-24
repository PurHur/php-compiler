<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringVarExport;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_export() on objects (#34506).
 *
 * Thin AOT {@see StringVarExport::implementThinScalarBridge} aborted TYPE_OBJECT.
 * Peer of {@see VarExportArrayLlvm} / {@see JitSerialize::encodePublicObjectProps}:
 * get_object_vars + compact `array(` bag; stdClass → `(object) …`, else `__set_state`.
 *
 * php-src: ext/standard/var.c — php_var_export_ex object branch
 */
final class VarExportObjectLlvm
{
    /**
     * @param Value $valuePtr `__value__*` typed as TYPE_OBJECT
     */
    public static function encode(Context $context, Value $valuePtr): Value
    {
        StringVarExport::ensureFormatHelpersForArrayLlvm($context);

        $objVar = new JitVariable(
            $context,
            JitVariable::TYPE_VALUE,
            JitVariable::KIND_VALUE,
            $valuePtr
        );
        $className = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );
        $i64 = $context->getTypeFromString('int64');
        $bag = VarExportArrayLlvm::encode(
            $context,
            $ht,
            $i64->constInt(0, false),
            true
        );

        $stdName = $context->builder->load($context->constantStringFromString('stdClass'));
        $cmp = JitStringCompare::strcmp($context, $className, $stdName);
        $isStd = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $i64->constInt(0, false)
        );

        $stdBlock = BasicBlockHelper::append($context, 've_obj_std');
        $setStateBlock = BasicBlockHelper::append($context, 've_obj_set_state');
        $done = BasicBlockHelper::append($context, 've_obj_done');
        $context->builder->branchIf($isStd, $stdBlock, $setStateBlock);

        $context->builder->positionAtEnd($stdBlock);
        $stdOut = JitStringConcat::concat(
            $context,
            $context->builder->load($context->constantStringFromString('(object) ')),
            $bag
        );
        $stdEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($setStateBlock);
        // php-src: \ClassName::__set_state(array(…))
        $bs = $context->builder->load($context->constantStringFromString('\\'));
        $setState = $context->builder->load($context->constantStringFromString('::__set_state('));
        $close = $context->builder->load($context->constantStringFromString(')'));
        $setOut = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $bs, $className),
                    $setState
                ),
                $bag
            ),
            $close
        );
        $setEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($stdOut, $stdEnd);
        $phi->addIncoming($setOut, $setEnd);

        return $phi;
    }
}
