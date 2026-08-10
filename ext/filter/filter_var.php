<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
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
            && JITVariable::TYPE_HASHTABLE !== ($optionsArg->type & ~JITVariable::IS_NATIVE_ARRAY)
            && JITVariable::TYPE_HASHTABLE !== $optionsArg->type) {
            throw new \LogicException('filter_var() options must be an integer flag bitmask or array');
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
        // php-src Z_PARAM_LONG $filter — null → Deprecated + coerce 0 → Unknown filter (#29723).
        if (JITVariable::TYPE_NULL === $filterArg->type || $filterArg->isNullConstant) {
            if ($context->callerStrictTypes) {
                throw new \LogicException(
                    'filter_var(): Argument #2 ($filter) must be of type int, null given'
                );
            }
            JitIntdiv::emitNullIntDeprecation($context, 'filter_var', 2, 'filter', 'int');
            JitBuiltinWarning::emit($context, VmFilter::unknownFilterWarningMessage(0, 'filter_var'));

            return JitFilter::boxedFalse($context);
        }
        // Constant value + filter + options array → VmFilter SSOT (options['default'], #29046).
        $folded = self::tryFoldConstOptionsFilter($context, $value, $filterArg, $optionsArg);
        if (null !== $folded) {
            return $folded;
        }
        // Array options without a compile-time assoc fold cannot use the int-flags path
        // (would mis-read the array as a long and corrupt the result; #29046).
        if (null !== $optionsArg && self::optionsArgIsArrayTyped($optionsArg)) {
            throw new \LogicException(
                'filter_var() array options require compile-time constant options in this AOT build (#29046)'
            );
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

    private static function optionsArgIsArrayTyped(JITVariable $optionsArg): bool
    {
        if (JITVariable::TYPE_VALUE === $optionsArg->type) {
            return true;
        }
        $masked = $optionsArg->type & ~JITVariable::IS_NATIVE_ARRAY;

        return JITVariable::TYPE_HASHTABLE === $masked
            || JITVariable::TYPE_HASHTABLE === $optionsArg->type;
    }

    /**
     * Fold filter_var() when value + filter + options[] are compile-time constants (#29046).
     */
    private static function tryFoldConstOptionsFilter(
        Context $context,
        JITVariable $value,
        JITVariable $filterArg,
        ?JITVariable $optionsArg
    ): ?Value {
        if (null === $optionsArg || !\is_array($optionsArg->compileTimeAssoc)) {
            return null;
        }
        $filterId = self::tryConstFilterId($context, $filterArg);
        if (null === $filterId) {
            return null;
        }
        $lit = $value->compileTimeString ?? JitStringArg::compileTimeLiteral($value);
        $valueVar = new Variable();
        if (null !== $lit) {
            $valueVar->string($lit);
        } elseif (null !== $value->compileTimeLong
            && (JITVariable::TYPE_NATIVE_LONG === $value->type || JITVariable::TYPE_VALUE === $value->type)) {
            $valueVar->int($value->compileTimeLong);
        } elseif (null !== $value->compileTimeFloat
            && (JITVariable::TYPE_NATIVE_DOUBLE === $value->type || JITVariable::TYPE_VALUE === $value->type)) {
            $valueVar->float($value->compileTimeFloat);
        } elseif (JITVariable::TYPE_NULL === $value->type) {
            $valueVar->null();
        } else {
            return null;
        }
        $optionsVar = self::phpArrayToVariable($optionsArg->compileTimeAssoc);
        $result = VmFilter::filterVar($valueVar, $filterId, $optionsVar);
        if (Variable::TYPE_ARRAY === $result->resolveIndirect()->type) {
            // Thin AOT array materialization is limited; leave FORCE_ARRAY to runtime paths.
            return null;
        }

        return self::boxVmFilterResult($context, $result);
    }

    /** @param array<string|int, mixed> $php */
    private static function phpArrayToVariable(array $php): Variable
    {
        $ht = new HashTable();
        foreach ($php as $key => $val) {
            $cell = self::phpScalarToVariable($val);
            if (\is_int($key)) {
                $ht->addIndex($key, $cell);
            } else {
                $ht->add((string) $key, $cell);
            }
        }
        $out = new Variable();
        $out->array($ht);

        return $out;
    }

    private static function phpScalarToVariable(mixed $val): Variable
    {
        $out = new Variable();
        if (null === $val) {
            $out->null();
        } elseif (\is_bool($val)) {
            $out->bool($val);
        } elseif (\is_int($val)) {
            $out->int($val);
        } elseif (\is_float($val)) {
            $out->float($val);
        } elseif (\is_string($val)) {
            $out->string($val);
        } elseif (\is_array($val)) {
            $out->copyFrom(self::phpArrayToVariable($val));
        } else {
            $out->null();
        }

        return $out;
    }

    private static function boxVmFilterResult(Context $context, Variable $result): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $resolved = $result->resolveIndirect();
        switch ($resolved->type) {
            case Variable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                break;
            case Variable::TYPE_BOOLEAN:
                JitValueBox::writeBool($context, $slot, $context->constantFromBool($resolved->toBool()));
                break;
            case Variable::TYPE_INTEGER:
                JitValueBox::writeLong($context, $slot, $context->constantFromInteger($resolved->toInt(), 'int64'));
                break;
            case Variable::TYPE_FLOAT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->constantFromFloat($resolved->toFloat())
                );
                break;
            case Variable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $context->builder->load($context->constantStringFromString($resolved->toString()))
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                break;
            default:
                throw new \LogicException('filter_var() const-fold returned unexpected type');
        }

        return $ptr;
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
                // php_filter_unsafe_raw honors STRIP_*/ENCODE_* via FilterSanitizeJitHelper (#29064).
                return JitFilter::sanitize(
                    $context,
                    $value,
                    $context->getTypeFromString('int64')->constInt($filterId, false),
                    $flags
                );
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
