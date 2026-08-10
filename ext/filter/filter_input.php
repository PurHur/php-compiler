<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('filter_input() requires two to four arguments in this compiler build');
        }
        $typeInt = VmFilter::resolveInputType($frame->calledArgs[0], 'filter_input');
        // php-src Z_PARAM_STR $var_name — caller strict_types → TypeError on null (#29776).
        $keyStr = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'filter_input',
            1,
            'var_name'
        );
        if ($argc >= 3) {
            $filterId = VmFilter::parseFilterIdArg($frame, 2, 'filter_input', 'filter', 3);
        } else {
            $filterId = VmFilter::FILTER_DEFAULT;
        }
        $options = null;
        if (4 === $argc) {
            $options = $frame->calledArgs[3]->resolveIndirect();
            if (!$options->isUndefined()
                && Variable::TYPE_NULL !== $options->type
                && Variable::TYPE_INTEGER !== $options->type
                && Variable::TYPE_ARRAY !== $options->type) {
                throw new \LogicException('filter_input() options must be an integer flag bitmask or array');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('filter_input() requires VM context in this compiler build');
        }
        // php-src: unknown filter (null→0) warns + returns false before missing-var null (#18943, #25926).
        if (!VmFilter::isSupportedFilter($filterId)) {
            filter_var::triggerUnknownFilterWarning($frame, $filterId, 'filter_input');
            $frame->returnVar->bool(false);

            return;
        }
        $value = VmFilter::requestInputValue($ctx, $typeInt, $keyStr);
        if (null === $value) {
            $frame->returnVar->null();

            return;
        }
        filter_var::writeReturn(
            $frame,
            VmFilter::filterVar($value, $filterId, $options, $frame, VmFilter::FILTER_REQUIRE_SCALAR, 'filter_input', 4)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 4) {
            throw new \LogicException('filter_input() requires two to four arguments in this compiler build');
        }
        if (\count($args) >= 3) {
            $filterArg = $args[2];
        } else {
            $i64 = $context->getTypeFromString('int64');
            $defaultFilter = $i64->constInt(VmFilter::FILTER_DEFAULT, false);
            $filterArg = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $defaultFilter
            );
        }
        // Fourth $options arg accepted; full options parsing deferred (#4404).
        // php-src Z_PARAM_STR $var_name — caller strict_types → TypeError on null (#29776).
        if (
            (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)
            && $context->callerStrictTypes
        ) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'filter_input(): Argument #2 ($var_name) must be of type string, null given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'filter_input_null_strict_dead');

            return JitFilter::boxedNull($context);
        }
        $keyStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'filter_input',
            1,
            'var_name'
        );
        $keyVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);

        // php-src: null filter coerces to 0 → unknown filter → false before var lookup (#25926).
        if (JITVariable::TYPE_NULL === $filterArg->type) {
            JitBuiltinWarning::emit(
                $context,
                VmFilter::unknownFilterWarningMessage(0, 'filter_input')
            );

            return JitFilter::boxedFalse($context);
        }

        $typeVal = JitFilterInputTypeArg::lower($context, $args[0]);
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
        $isCookie = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_COOKIE, false)
        );
        $isEnv = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_ENV, false)
        );
        $isServer = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_SERVER, false)
        );

        $id = 'fi'.spl_object_id($context);
        $pickPostBlock = BasicBlockHelper::append($context, 'filter_input_pick_post_'.$id);
        $getBlock = BasicBlockHelper::append($context, 'filter_input_get_'.$id);
        $postBlock = BasicBlockHelper::append($context, 'filter_input_post_'.$id);
        $cookieBlock = BasicBlockHelper::append($context, 'filter_input_cookie_'.$id);
        $envBlock = BasicBlockHelper::append($context, 'filter_input_env_'.$id);
        $serverBlock = BasicBlockHelper::append($context, 'filter_input_server_'.$id);
        $badTypeBlock = BasicBlockHelper::append($context, 'filter_input_bad_type_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filter_input_type_done_'.$id);

        $cookieBodyBlock = BasicBlockHelper::append($context, 'filter_input_cookie_body_'.$id);
        $envBodyBlock = BasicBlockHelper::append($context, 'filter_input_env_body_'.$id);
        $serverBodyBlock = BasicBlockHelper::append($context, 'filter_input_server_body_'.$id);

        $context->builder->branchIf($isGet, $getBlock, $pickPostBlock);
        $context->builder->positionAtEnd($pickPostBlock);
        $context->builder->branchIf($isPost, $postBlock, $cookieBlock);

        $context->builder->positionAtEnd($cookieBlock);
        $context->builder->branchIf($isCookie, $cookieBodyBlock, $envBlock);
        $context->builder->positionAtEnd($cookieBodyBlock);
        $cookieResult = self::filterFromSuperglobal($context, '_COOKIE', $keyVar, $filterArg);
        $cookieTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($envBlock);
        $context->builder->branchIf($isEnv, $envBodyBlock, $serverBlock);
        $context->builder->positionAtEnd($envBodyBlock);
        $envResult = self::filterFromSuperglobal($context, '_ENV', $keyVar, $filterArg);
        $envTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        // No INPUT_SESSION in php-src — unknown types fall through to badType (#24358).
        $context->builder->positionAtEnd($serverBlock);
        $context->builder->branchIf($isServer, $serverBodyBlock, $badTypeBlock);
        $context->builder->positionAtEnd($serverBodyBlock);
        $serverResult = self::filterFromSuperglobal($context, '_SERVER', $keyVar, $filterArg);
        $serverTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($badTypeBlock);
        $badResult = JitFilter::boxedNull($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($getBlock);
        $getResult = self::filterFromSuperglobal($context, '_GET', $keyVar, $filterArg);
        $getTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($postBlock);
        $postResult = self::filterFromSuperglobal($context, '_POST', $keyVar, $filterArg);
        $postTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($badResult->typeOf());
        $phi->addIncoming($badResult, $badTypeBlock);
        $phi->addIncoming($getResult, $getTail);
        $phi->addIncoming($postResult, $postTail);
        $phi->addIncoming($cookieResult, $cookieTail);
        $phi->addIncoming($envResult, $envTail);
        $phi->addIncoming($serverResult, $serverTail);

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
