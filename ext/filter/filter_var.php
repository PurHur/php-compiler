<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** filter_var() subset — FILTER_VALIDATE_INT/BOOLEAN/FLOAT/REGEXP/EMAIL/URL/IP (#104, #4742, #5020, #6028, #4403). */
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
            if (!$options->isUndefined()
                && Variable::TYPE_NULL !== $options->type
                && Variable::TYPE_INTEGER !== $options->type
                && Variable::TYPE_ARRAY !== $options->type) {
                throw new \LogicException('filter_var() options must be an integer flag bitmask or array');
            }
        }
        $filterId = $filter->toInt();
        if (!VmFilter::isSupportedFilter($filterId)) {
            self::triggerUnknownFilterWarning($frame, $filterId);
        }
        self::writeReturn($frame, VmFilter::filterVar($value, $filterId, $options));
    }

    public static function triggerUnknownFilterWarning(Frame $frame, int $filterId): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            VmFilter::unknownFilterWarningMessage($filterId),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('filter_var() requires two or three arguments in this compiler build');
        }
        $optionsArg = \count($args) > 2 ? $args[2] : null;
        if (null !== $optionsArg
            && JITVariable::TYPE_NULL !== $optionsArg->type
            && JITVariable::TYPE_NATIVE_LONG !== $optionsArg->type
            && JITVariable::TYPE_VALUE !== $optionsArg->type
            && JITVariable::TYPE_HASHTABLE !== ($optionsArg->type & ~JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('filter_var() options must be an integer flag bitmask or array');
        }
        if (null !== $optionsArg
            && JITVariable::TYPE_NULL !== $optionsArg->type
            && (JITVariable::TYPE_HASHTABLE === ($optionsArg->type & ~JITVariable::IS_NATIVE_ARRAY)
                || JITVariable::TYPE_HASHTABLE === $optionsArg->type)) {
            throw new \LogicException('filter_var() array options are not supported in JIT in this compiler build');
        }

        $value = JitFilter::asValueVar($context, $args[0]);
        $filterVal = JitFilter::loadFilterId($context, $args[1]);
        $nullOnFailure = JitFilter::loadNullOnFailureFlag($context, $optionsArg);
        $i64 = $context->getTypeFromString('int64');
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_INT, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_BOOLEAN, false)
        );
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_FLOAT, false)
        );
        $isEmail = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_EMAIL, false)
        );
        $isUrl = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_URL, false)
        );
        $isIp = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_IP, false)
        );

        $intBlock = BasicBlockHelper::append($context, 'filter_var_int');
        $otherBlock = BasicBlockHelper::append($context, 'filter_var_other');
        $boolBlock = BasicBlockHelper::append($context, 'filter_var_bool');
        $boolOtherBlock = BasicBlockHelper::append($context, 'filter_var_bool_other');
        $floatBlock = BasicBlockHelper::append($context, 'filter_var_float');
        $floatOtherBlock = BasicBlockHelper::append($context, 'filter_var_float_other');
        $emailBlock = BasicBlockHelper::append($context, 'filter_var_email');
        $emailOtherBlock = BasicBlockHelper::append($context, 'filter_var_email_other');
        $urlBlock = BasicBlockHelper::append($context, 'filter_var_url');
        $urlOtherBlock = BasicBlockHelper::append($context, 'filter_var_url_other');
        $ipBlock = BasicBlockHelper::append($context, 'filter_var_ip');
        $failBlock = BasicBlockHelper::append($context, 'filter_var_fail');
        $doneBlock = BasicBlockHelper::append($context, 'filter_var_done');
        $context->builder->branchIf($isInt, $intBlock, $otherBlock);

        $context->builder->positionAtEnd($intBlock);
        $flags = JitFilter::loadFilterFlags($context, $optionsArg);
        $intResult = JitFilter::validateInt($context, $value, $flags);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $intResult = JitFilter::applyNullOnFailure($context, $intResult, $nullOnFailure);
        }
        $intTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($otherBlock);
        $context->builder->branchIf($isBool, $boolBlock, $boolOtherBlock);

        $context->builder->positionAtEnd($boolBlock);
        $boolResult = JitFilter::validateBoolean($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $boolResult = JitFilter::applyNullOnFailure($context, $boolResult, $nullOnFailure);
        }
        $boolTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($boolOtherBlock);
        $context->builder->branchIf($isFloat, $floatBlock, $floatOtherBlock);

        $context->builder->positionAtEnd($floatBlock);
        $floatResult = JitFilter::validateFloat($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $floatResult = JitFilter::applyNullOnFailure($context, $floatResult, $nullOnFailure);
        }
        $floatTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatOtherBlock);
        $context->builder->branchIf($isEmail, $emailBlock, $emailOtherBlock);

        $context->builder->positionAtEnd($emailBlock);
        $emailResult = JitFilter::validateEmail($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $emailResult = JitFilter::applyNullOnFailure($context, $emailResult, $nullOnFailure);
        }
        $emailTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($emailOtherBlock);
        $context->builder->branchIf($isUrl, $urlBlock, $urlOtherBlock);

        $context->builder->positionAtEnd($urlBlock);
        $urlResult = JitFilter::validateUrl($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $urlResult = JitFilter::applyNullOnFailure($context, $urlResult, $nullOnFailure);
        }
        $urlTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($urlOtherBlock);
        $context->builder->branchIf($isIp, $ipBlock, $failBlock);

        $context->builder->positionAtEnd($ipBlock);
        $ipResult = JitFilter::validateIp($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $ipResult = JitFilter::applyNullOnFailure($context, $ipResult, $nullOnFailure);
        }
        $ipTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseResult = JitFilter::boxedFalse($context);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($intResult->typeOf());
        $phi->addIncoming($intResult, $intTail);
        $phi->addIncoming($boolResult, $boolTail);
        $phi->addIncoming($floatResult, $floatTail);
        $phi->addIncoming($emailResult, $emailTail);
        $phi->addIncoming($urlResult, $urlTail);
        $phi->addIncoming($ipResult, $ipTail);
        $phi->addIncoming($falseResult, $failTail);

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
            case Variable::TYPE_FLOAT:
                $frame->returnVar->float($result->toFloat());
                break;
            case Variable::TYPE_NULL:
                $frame->returnVar->null();
                break;
            default:
                throw new \LogicException('filter_var() returned unexpected type');
        }
    }
}
