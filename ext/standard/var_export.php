<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * var_export() subset for bootstrap/AOT (bool scalars; issue isset_array_offset fixture).
 */
final class var_export extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('var_export() requires one or two arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        $return = false;
        if (2 === $argc) {
            $retArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $retArg->type) {
                throw new \LogicException('var_export() return argument must be boolean in this compiler build');
            }
            $return = $retArg->toBool();
        }
        $exported = self::exportVm($v);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($exported);
        } else {
            echo $exported;
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (count($args) < 1 || count($args) > 2) {
            throw new \LogicException('var_export() requires one or two arguments in this compiler build');
        }
        if (count($args) >= 2) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[1]->type) {
                throw new \LogicException('var_export() return argument must be boolean in this compiler build');
            }
            $returnArg = $context->helper->loadValue($args[1]);
            $returnBlock = BasicBlockHelper::append($context, 'var_export_return');
            $echoBlock = BasicBlockHelper::append($context, 'var_export_echo');
            $doneBlock = BasicBlockHelper::append($context, 'var_export_done');
            $context->builder->branchIf($returnArg, $returnBlock, $echoBlock);
            $context->builder->positionAtEnd($returnBlock);
            $str = self::exportJit($context, $args[0]);
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($echoBlock);
            self::echoJit($context, $args[0]);
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($doneBlock);

            return $str;
        }
        self::echoJit($context, $args[0]);

        $this->jitString($context, $args[0], 'varexport() argument #1');
        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function exportVm(Variable $v): string
    {
        if (Variable::TYPE_BOOLEAN === $v->type) {
            return $v->toBool() ? 'true' : 'false';
        }
        if (Variable::TYPE_NULL === $v->type) {
            return 'NULL';
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    private static function echoJit(Context $context, JITVariable $arg): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $printf = $context->lookupFunction('printf');
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            self::echoBoolJit($context, self::boolValForBranch($context, $arg));

            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            $context->builder->call(
                $printf,
                $context->builder->pointerCast($context->constantFromString('NULL'), $charPtr)
            );

            return;
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    private static function boolValForBranch(Context $context, JITVariable $arg): Value
    {
        $boolVal = JITVariable::TYPE_VALUE === $arg->type
            ? $context->castToBool(JitValueBox::valuePtrFromVariable($context, $arg))
            : $context->helper->loadValue($arg);
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return $boolVal;
        }
        $i1 = $context->getTypeFromString('int1');
        $slot = $context->builder->alloca($i1, 1, 'var_export_bool_tmp');
        $context->builder->store($boolVal, $slot);

        return $context->builder->load($slot);
    }

    private static function echoBoolJit(Context $context, Value $boolVal): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $printf = $context->lookupFunction('printf');
        $trueBlock = BasicBlockHelper::append($context, 'var_export_bool_true');
        $falseBlock = BasicBlockHelper::append($context, 'var_export_bool_false');
        $doneBlock = BasicBlockHelper::append($context, 'var_export_bool_done');
        $context->builder->branchIf($boolVal, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $context->builder->call(
            $printf,
            $context->builder->pointerCast($context->constantFromString('true'), $charPtr)
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->call(
            $printf,
            $context->builder->pointerCast($context->constantFromString('false'), $charPtr)
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    private static function exportJit(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return self::exportBoolJit($context, self::boolValForBranch($context, $arg));
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString('NULL'));
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    private static function exportBoolJit(Context $context, Value $boolVal): Value
    {
        $trueStr = $context->constantStringFromString('true');
        $falseStr = $context->constantStringFromString('false');

        return $context->builder->select(
            $boolVal,
            $context->builder->load($trueStr),
            $context->builder->load($falseStr)
        );
    }
}
