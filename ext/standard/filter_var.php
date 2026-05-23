<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** filter_var() subset — FILTER_VALIDATE_INT and FILTER_VALIDATE_EMAIL (issue #104). */
final class filter_var extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('filter_var() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        $filter = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $filter->type) {
            throw new \LogicException('filter_var() filter must be an integer in this compiler build');
        }
        $options = null;
        if (3 === $argc) {
            $options = $frame->calledArgs[2]->resolveIndirect();
            if (!$options->isUndefined() && Variable::TYPE_NULL !== $options->type) {
                throw new \LogicException('filter_var() options are not supported in this compiler build');
            }
        }
        self::writeReturn($frame, VmFilter::filterVar($value, $filter->toInt(), $options));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('filter_var() requires two or three arguments in this compiler build');
        }
        if (\count($args) > 2 && JITVariable::TYPE_NULL !== $args[2]->type) {
            throw new \LogicException('filter_var() options are not supported in this compiler build');
        }

        $value = JitFilter::asValueVar($context, $args[0]);
        $filterVal = JitFilter::loadFilterId($context, $args[1]);
        $i64 = $context->getTypeFromString('int64');
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_INT, false)
        );

        $intBlock = BasicBlockHelper::append($context, 'filter_var_int');
        $otherBlock = BasicBlockHelper::append($context, 'filter_var_other');
        $doneBlock = BasicBlockHelper::append($context, 'filter_var_done');
        $context->builder->branchIf($isInt, $intBlock, $otherBlock);

        $context->builder->positionAtEnd($intBlock);
        $intResult = JitFilter::validateInt($context, $value);
        $intTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($otherBlock);
        $otherResult = JitFilter::validateEmail($context, $value);
        $otherTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($intResult->typeOf());
        $phi->addIncoming($intResult, $intTail);
        $phi->addIncoming($otherResult, $otherTail);

        $this->jitString($context, $args[0], 'filtervar() argument #1');
        return $phi;
    }

    public static function writeReturn(Frame $frame, Variable $result): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        switch ($result->type) {
            case Variable::TYPE_INTEGER:
                $frame->returnVar->int($result->toInt());
                break;
            case Variable::TYPE_STRING:
                $frame->returnVar->string($result->toString());
                break;
            case Variable::TYPE_BOOLEAN:
                $frame->returnVar->bool($result->toBool());
                break;
            default:
                throw new \LogicException('filter_var() returned unexpected type');
        }
    }
}
