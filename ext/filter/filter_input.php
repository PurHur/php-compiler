<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        $typeInt = VmFilter::resolveInputType($frame->calledArgs[0], 'filter_input');
        $keyStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'filter_input',
            1,
            'variable_name'
        );
        $filter = InternalStrictArg::requireBuiltinTypedInt($frame, 2, 'filter_input', 'filter');
        if (4 === $argc) {
            $options = $frame->calledArgs[3]->resolveIndirect();
            if (!$options->isUndefined() && Variable::TYPE_NULL !== $options->type) {
                throw new \LogicException('filter_input() options are not supported in this compiler build');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('filter_input() requires VM context in this compiler build');
        }
        $sgName = VmFilter::inputSuperglobalName($typeInt);
        $sg = $ctx->getSuperglobal($sgName);
        if (null === $sg || Variable::TYPE_ARRAY !== $sg->type) {
            $frame->returnVar->null();

            return;
        }
        $keyVar = new Variable();
        $keyVar->string($keyStr);
        $ht = $sg->toArray();
        if (!$ht->offsetIsSet($keyVar)) {
            $frame->returnVar->null();

            return;
        }
        $stored = $ht->find($keyStr);
        if (null === $stored) {
            $frame->returnVar->null();

            return;
        }
        $value = $stored->resolveIndirect();
        $filterId = $filter->toInt();
        if (!VmFilter::isSupportedFilter($filterId)) {
            filter_var::triggerUnknownFilterWarning($frame, $filterId);
        }
        filter_var::writeReturn($frame, VmFilter::filterVar($value, $filterId, null));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 4) {
            throw new \LogicException('filter_input() requires three or four arguments in this compiler build');
        }
        JitInternalStrictArg::requireBuiltinTypedInt($context, $args[2], 'filter_input', 'filter', 3);
        if (\count($args) > 3 && JITVariable::TYPE_NULL !== $args[3]->type) {
            throw new \LogicException('filter_input() options are not supported in this compiler build');
        }

        $keyStr = JitStringBuiltinArg::lower($context, $args[1], 'filter_input', 1, 'variable_name');
        $keyVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);

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
        $isSession = $context->builder->icmp(
            Builder::INT_EQ,
            $typeVal,
            $i64->constInt(VmFilter::INPUT_SESSION, false)
        );

        $id = 'fi'.spl_object_id($context);
        $pickPostBlock = BasicBlockHelper::append($context, 'filter_input_pick_post_'.$id);
        $getBlock = BasicBlockHelper::append($context, 'filter_input_get_'.$id);
        $postBlock = BasicBlockHelper::append($context, 'filter_input_post_'.$id);
        $cookieBlock = BasicBlockHelper::append($context, 'filter_input_cookie_'.$id);
        $envBlock = BasicBlockHelper::append($context, 'filter_input_env_'.$id);
        $serverBlock = BasicBlockHelper::append($context, 'filter_input_server_'.$id);
        $sessionBlock = BasicBlockHelper::append($context, 'filter_input_session_'.$id);
        $badTypeBlock = BasicBlockHelper::append($context, 'filter_input_bad_type_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filter_input_type_done_'.$id);

        $cookieBodyBlock = BasicBlockHelper::append($context, 'filter_input_cookie_body_'.$id);
        $envBodyBlock = BasicBlockHelper::append($context, 'filter_input_env_body_'.$id);
        $serverBodyBlock = BasicBlockHelper::append($context, 'filter_input_server_body_'.$id);
        $sessionBodyBlock = BasicBlockHelper::append($context, 'filter_input_session_body_'.$id);

        $context->builder->branchIf($isGet, $getBlock, $pickPostBlock);
        $context->builder->positionAtEnd($pickPostBlock);
        $context->builder->branchIf($isPost, $postBlock, $cookieBlock);

        $context->builder->positionAtEnd($cookieBlock);
        $context->builder->branchIf($isCookie, $cookieBodyBlock, $envBlock);
        $context->builder->positionAtEnd($cookieBodyBlock);
        $cookieResult = self::filterFromSuperglobal($context, '_COOKIE', $keyVar, $args[2]);
        $cookieTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($envBlock);
        $context->builder->branchIf($isEnv, $envBodyBlock, $serverBlock);
        $context->builder->positionAtEnd($envBodyBlock);
        $envResult = self::filterFromSuperglobal($context, '_ENV', $keyVar, $args[2]);
        $envTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($serverBlock);
        $context->builder->branchIf($isServer, $serverBodyBlock, $sessionBlock);
        $context->builder->positionAtEnd($serverBodyBlock);
        $serverResult = self::filterFromSuperglobal($context, '_SERVER', $keyVar, $args[2]);
        $serverTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sessionBlock);
        $context->builder->branchIf($isSession, $sessionBodyBlock, $badTypeBlock);
        $context->builder->positionAtEnd($sessionBodyBlock);
        $sessionResult = self::filterFromSuperglobal($context, '_SESSION', $keyVar, $args[2]);
        $sessionTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($badTypeBlock);
        $badResult = JitFilter::boxedNull($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($getBlock);
        $getResult = self::filterFromSuperglobal($context, '_GET', $keyVar, $args[2]);
        $getTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($postBlock);
        $postResult = self::filterFromSuperglobal($context, '_POST', $keyVar, $args[2]);
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
        $phi->addIncoming($sessionResult, $sessionTail);

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
