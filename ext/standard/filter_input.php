<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** filter_input() subset for INPUT_GET and INPUT_POST (issue #104). */
final class filter_input extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('filter_input() requires three or four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = $frame->calledArgs[0]->resolveIndirect();
        $key = $frame->calledArgs[1]->resolveIndirect();
        $filter = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $type->type
            || Variable::TYPE_STRING !== $key->type
            || Variable::TYPE_INTEGER !== $filter->type) {
            throw new \LogicException(
                'filter_input() requires (int type, string key, int filter) in this compiler build'
            );
        }
        if (4 === $argc) {
            $options = $frame->calledArgs[3]->resolveIndirect();
            if (!$options->isUndefined() && Variable::TYPE_NULL !== $options->type) {
                throw new \LogicException('filter_input() options are not supported in this compiler build');
            }
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('filter_input() requires VM context in this compiler build');
        }
        $sgName = VmFilter::inputSuperglobalName($type->toInt());
        $sg = $ctx->getSuperglobal($sgName);
        if (null === $sg || Variable::TYPE_ARRAY !== $sg->type) {
            $frame->returnVar->null();

            return;
        }
        $keyVar = new Variable();
        $keyVar->string($key->toString());
        $ht = $sg->toArray();
        if (!$ht->offsetIsSet($keyVar)) {
            $frame->returnVar->null();

            return;
        }
        $stored = $ht->find($key->toString());
        if (null === $stored) {
            $frame->returnVar->null();

            return;
        }
        $value = $stored->resolveIndirect();
        filter_var::writeReturn($frame, VmFilter::filterVar($value, $filter->toInt(), null));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 4) {
            throw new \LogicException('filter_input() requires three or four arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('filter_input() key must be a string in this compiler build');
        }
        if (\count($args) > 3 && JITVariable::TYPE_NULL !== $args[3]->type) {
            throw new \LogicException('filter_input() options are not supported in this compiler build');
        }

        $typeVal = JitFilter::loadFilterId($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $isGet = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_GET, false)
        );
        $isPost = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_POST, false)
        );

        $id = 'fi'.spl_object_id($context);
        $pickPostBlock = BasicBlockHelper::append($context, 'filter_input_pick_post_'.$id);
        $getBlock = BasicBlockHelper::append($context, 'filter_input_get_'.$id);
        $postBlock = BasicBlockHelper::append($context, 'filter_input_post_'.$id);
        $badTypeBlock = BasicBlockHelper::append($context, 'filter_input_bad_type_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filter_input_type_done_'.$id);

        $context->builder->branchIf($isGet, $getBlock, $pickPostBlock);
        $context->builder->positionAtEnd($pickPostBlock);
        $context->builder->branchIf($isPost, $postBlock, $badTypeBlock);

        $context->builder->positionAtEnd($badTypeBlock);
        $badResult = JitFilter::boxedNull($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($getBlock);
        $getResult = self::filterFromSuperglobal($context, '_GET', $args[1], $args[2]);
        $getTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($postBlock);
        $postResult = self::filterFromSuperglobal($context, '_POST', $args[1], $args[2]);
        $postTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($badResult->typeOf());
        $phi->addIncoming($badResult, $badTypeBlock);
        $phi->addIncoming($getResult, $getTail);
        $phi->addIncoming($postResult, $postTail);

        $this->jitString($context, $args[0], 'filterinput() argument #1');
        return $phi;
    }

    private static function filterFromSuperglobal(
        Context $context,
        string $superglobal,
        JITVariable $key,
        JITVariable $filter
    ): Value {
        $htVar = SuperglobalInit::load($context, $superglobal);
        $exists = (new array_key_exists())->call($context, $key, $htVar);
        $missingBlock = BasicBlockHelper::append($context, 'filter_input_missing_'.$superglobal);
        $presentBlock = BasicBlockHelper::append($context, 'filter_input_present_'.$superglobal);
        $doneBlock = BasicBlockHelper::append($context, 'filter_input_sg_done_'.$superglobal);
        $context->builder->branchIf($exists, $presentBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $nullResult = JitFilter::boxedNull($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($presentBlock);
        $ht = $context->helper->loadValue($htVar);
        $keyVal = $context->helper->loadValue($key);
        $boxed = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyVal
        );
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxed
        );
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $str);
        $filterVal = JitFilter::loadFilterId($context, $filter);
        $i64 = $context->getTypeFromString('int64');
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_INT, false)
        );
        $intFilterBlock = BasicBlockHelper::append($context, 'filter_input_int_filter_'.$superglobal);
        $emailFilterBlock = BasicBlockHelper::append($context, 'filter_input_email_filter_'.$superglobal);
        $filterDone = BasicBlockHelper::append($context, 'filter_input_filter_done_'.$superglobal);
        $context->builder->branchIf($isInt, $intFilterBlock, $emailFilterBlock);

        $context->builder->positionAtEnd($intFilterBlock);
        $intFiltered = JitFilter::validateInt($context, $strVar);
        $intFilterTail = $context->builder->getInsertBlock();
        $context->builder->branch($filterDone);

        $context->builder->positionAtEnd($emailFilterBlock);
        $emailFiltered = JitFilter::validateEmail($context, $strVar);
        $emailFilterTail = $context->builder->getInsertBlock();
        $context->builder->branch($filterDone);

        $context->builder->positionAtEnd($filterDone);
        $filtered = $context->builder->phi($intFiltered->typeOf());
        $filtered->addIncoming($intFiltered, $intFilterTail);
        $filtered->addIncoming($emailFiltered, $emailFilterTail);
        $filterDoneTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($filtered->typeOf());
        $phi->addIncoming($nullResult, $missingBlock);
        $phi->addIncoming($filtered, $filterDoneTail);

        return $phi;
    }
}
