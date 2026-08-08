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

/** filter_var() subset — FILTER_VALIDATE_INT/BOOLEAN/FLOAT/REGEXP/EMAIL/URL/IP/MAC (#104, #4742, #5020, #6028, #4403, #17411). */
final class filter_var extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('filter_var() expects at least 1 argument, '.$argc.' given');
        }
        if ($argc > 3) {
            throw new \ArgumentCountError('filter_var() expects at most 3 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        // php-src filter.stub.php: filter_var(mixed $value, int $filter = FILTER_DEFAULT, …)
        if ($argc >= 2) {
            $filterId = VmFilter::parseFilterIdArg($frame, 1, 'filter_var', 'filter', 2);
        } else {
            $filterId = VmFilter::FILTER_DEFAULT;
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
        if (!VmFilter::isSupportedFilter($filterId)) {
            self::triggerUnknownFilterWarning($frame, $filterId);
        }
        self::writeReturn($frame, VmFilter::filterVar($value, $filterId, $options, $frame));
    }

    public static function triggerUnknownFilterWarning(Frame $frame, int $filterId, string $function = 'filter_var'): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            VmFilter::unknownFilterWarningMessage($filterId, $function),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('filter_var() expects at least 1 argument, '.$argc.' given');
        }
        if ($argc > 3) {
            throw new \ArgumentCountError('filter_var() expects at most 3 arguments, '.$argc.' given');
        }
        $optionsArg = $argc > 2 ? $args[2] : null;
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
        if ($argc >= 2) {
            $filterArg = $args[1];
        } else {
            $i64Default = $context->getTypeFromString('int64');
            $defaultFilter = $i64Default->constInt(VmFilter::FILTER_DEFAULT, false);
            $filterArg = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $defaultFilter
            );
        }
        // One-arg defaults to FILTER_DEFAULT (php-src stub). Skip the validate/sanitize
        // mega-CFG so AOT does not pull broken helper ABIs for this passthrough (#20988).
        if (1 === $argc) {
            return JitFilter::boxFilterDefault($context, $value);
        }
        // Compile-time filter id — emit only that validator (avoids mega-CFG pulling
        // FilterEmailValidate → __compiler_preg_match without a bridge, #26853 / peer #20988).
        $constFilter = self::tryConstFilterId($context, $filterArg);
        if (null !== $constFilter) {
            return self::dispatchConstFilter($context, $value, $constFilter, $optionsArg);
        }
        $filterVal = JitFilter::loadFilterId($context, $filterArg);
        $nullOnFailure = JitFilter::loadNullOnFailureFlag($context, $optionsArg);
        JitFilter::assertThrowNullExclusiveConst($context, $optionsArg);
        $throwOnFailure = JitFilter::loadThrowOnFailureFlag($context, $optionsArg);
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
        $isDomain = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_DOMAIN, false)
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
        $isMac = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_VALIDATE_MAC, false)
        );
        $flags = JitFilter::loadFilterFlags($context, $optionsArg);

        $intBlock = BasicBlockHelper::append($context, 'filter_var_int');
        $otherBlock = BasicBlockHelper::append($context, 'filter_var_other');
        $boolBlock = BasicBlockHelper::append($context, 'filter_var_bool');
        $boolOtherBlock = BasicBlockHelper::append($context, 'filter_var_bool_other');
        $floatBlock = BasicBlockHelper::append($context, 'filter_var_float');
        $floatOtherBlock = BasicBlockHelper::append($context, 'filter_var_float_other');
        $domainBlock = BasicBlockHelper::append($context, 'filter_var_domain');
        $domainOtherBlock = BasicBlockHelper::append($context, 'filter_var_domain_other');
        $emailBlock = BasicBlockHelper::append($context, 'filter_var_email');
        $emailOtherBlock = BasicBlockHelper::append($context, 'filter_var_email_other');
        $urlBlock = BasicBlockHelper::append($context, 'filter_var_url');
        $urlOtherBlock = BasicBlockHelper::append($context, 'filter_var_url_other');
        $ipBlock = BasicBlockHelper::append($context, 'filter_var_ip');
        $macCheckBlock = BasicBlockHelper::append($context, 'filter_var_mac_check');
        $macBlock = BasicBlockHelper::append($context, 'filter_var_mac');
        $sanitizeCheckBlock = BasicBlockHelper::append($context, 'filter_var_sanitize_check');
        $sanitizeBlock = BasicBlockHelper::append($context, 'filter_var_sanitize');
        $failBlock = BasicBlockHelper::append($context, 'filter_var_fail');
        $doneBlock = BasicBlockHelper::append($context, 'filter_var_done');
        $context->builder->branchIf($isInt, $intBlock, $otherBlock);

        $context->builder->positionAtEnd($intBlock);
        $intResult = JitFilter::validateInt($context, $value, $flags);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $intResult = JitFilter::applyNullOnFailure($context, $intResult, $nullOnFailure);
        }
        $intTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($otherBlock);
        $context->builder->branchIf($isBool, $boolBlock, $boolOtherBlock);

        $context->builder->positionAtEnd($boolBlock);
        $boolResult = JitFilter::validateBoolean($context, $value, $nullOnFailure);
        $boolTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($boolOtherBlock);
        $context->builder->branchIf($isFloat, $floatBlock, $floatOtherBlock);

        $context->builder->positionAtEnd($floatBlock);
        $floatResult = JitFilter::validateFloat($context, $value, $flags);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $floatResult = JitFilter::applyNullOnFailure($context, $floatResult, $nullOnFailure);
        }
        $floatTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatOtherBlock);
        $context->builder->branchIf($isDomain, $domainBlock, $domainOtherBlock);

        $context->builder->positionAtEnd($domainBlock);
        $domainResult = JitFilter::validateDomain($context, $value, $flags);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $domainResult = JitFilter::applyNullOnFailure($context, $domainResult, $nullOnFailure);
        }
        $domainTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($domainOtherBlock);
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
        $context->builder->branchIf($isIp, $ipBlock, $macCheckBlock);

        $context->builder->positionAtEnd($macCheckBlock);
        $context->builder->branchIf($isMac, $macBlock, $sanitizeCheckBlock);

        $context->builder->positionAtEnd($sanitizeCheckBlock);
        $isSanitize = JitFilter::isSanitizeFilterId($context, $filterVal);
        $context->builder->branchIf($isSanitize, $sanitizeBlock, $failBlock);

        $context->builder->positionAtEnd($ipBlock);
        $ipResult = JitFilter::validateIp($context, $value, $flags);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $ipResult = JitFilter::applyNullOnFailure($context, $ipResult, $nullOnFailure);
        }
        $ipTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($macBlock);
        $macResult = JitFilter::validateMac($context, $value);
        if (null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type) {
            $macResult = JitFilter::applyNullOnFailure($context, $macResult, $nullOnFailure);
        }
        $macTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sanitizeBlock);
        $sanitizeResult = JitFilter::sanitize($context, $value, $filterVal, $flags);
        $sanitizeTail = $context->builder->getInsertBlock();
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
        $phi->addIncoming($domainResult, $domainTail);
        $phi->addIncoming($emailResult, $emailTail);
        $phi->addIncoming($urlResult, $urlTail);
        $phi->addIncoming($ipResult, $ipTail);
        $phi->addIncoming($macResult, $macTail);
        $phi->addIncoming($sanitizeResult, $sanitizeTail);
        $phi->addIncoming($falseResult, $failTail);

        return JitFilter::applyThrowOnFailure($context, $phi, $throwOnFailure, 'unknown');
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
            case Variable::TYPE_ARRAY:
            case Variable::TYPE_OBJECT:
                $frame->returnVar->copyFrom($result);
                break;
            default:
                throw new \LogicException('filter_var() returned unexpected type');
        }
    }

    /** Compile-time FILTER_* id from a native long constant or named constant load (#26853). */
    private static function tryConstFilterId(Context $context, JITVariable $filterArg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $filterArg->type
            || JITVariable::KIND_VALUE !== $filterArg->kind
            || null === $filterArg->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($filterArg->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($filterArg->value->value);
        }
        // FILTER_* constants lower as loads from registered globals (json_encode peer #21723).
        if (null === $lib->LLVMIsALoadInst($filterArg->value->value)) {
            return null;
        }
        $ptr = $filterArg->value->getOperand(0);
        $name = $lib->LLVMGetValueName($ptr->value)?->toString() ?? '';
        if ('' === $name || !isset($context->constants[$name])) {
            return null;
        }
        if ($context->constants[$name][0] !== $filterArg->type) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($name);
        if (null === $phpVar || \PHPCompiler\VM\Variable::TYPE_INTEGER !== $phpVar->type) {
            return null;
        }

        return $phpVar->toInt();
    }

    /**
     * Single-filter AOT/JIT path — no mega-CFG (#26853).
     */
    private static function dispatchConstFilter(
        Context $context,
        JITVariable $value,
        int $filterId,
        ?JITVariable $optionsArg
    ): Value {
        $nullOnFailure = JitFilter::loadNullOnFailureFlag($context, $optionsArg);
        JitFilter::assertThrowNullExclusiveConst($context, $optionsArg);
        $throwOnFailure = JitFilter::loadThrowOnFailureFlag($context, $optionsArg);
        $flags = JitFilter::loadFilterFlags($context, $optionsArg);
        $applyNull = null !== $optionsArg && JITVariable::TYPE_NULL !== $optionsArg->type;
        $filterName = VmFilter::nameForFilterId($filterId);

        switch ($filterId) {
            case VmFilter::FILTER_DEFAULT:
            case VmFilter::FILTER_UNSAFE_RAW:
                return JitFilter::boxFilterDefault($context, $value);
            case VmFilter::FILTER_VALIDATE_INT:
                $result = JitFilter::validateInt($context, $value, $flags);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_BOOLEAN:
                $result = JitFilter::validateBoolean($context, $value, $nullOnFailure);

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_FLOAT:
                $result = JitFilter::validateFloat($context, $value, $flags);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_DOMAIN:
                $result = JitFilter::validateDomain($context, $value, $flags);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_EMAIL:
                $result = JitFilter::validateEmail($context, $value);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_URL:
                $result = JitFilter::validateUrl($context, $value);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_IP:
                $result = JitFilter::validateIp($context, $value, $flags);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            case VmFilter::FILTER_VALIDATE_MAC:
                $result = JitFilter::validateMac($context, $value);
                $result = $applyNull
                    ? JitFilter::applyNullOnFailure($context, $result, $nullOnFailure)
                    : $result;

                return JitFilter::applyThrowOnFailure($context, $result, $throwOnFailure, $filterName);
            default:
                if (VmFilter::isSanitizeFilter($filterId)) {
                    return JitFilter::sanitize($context, $value, $context->getTypeFromString('int64')->constInt($filterId, false), $flags);
                }

                return JitFilter::applyThrowOnFailure(
                    $context,
                    JitFilter::boxedFalse($context),
                    $throwOnFailure,
                    $filterName
                );
        }
    }
}
