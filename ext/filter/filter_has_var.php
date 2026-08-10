<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** filter_has_var() — probe INPUT_* superglobal for a key (php-src ext/filter/filter.c; #3294). */
final class filter_has_var extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('filter_has_var() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('filter_has_var() requires VM context in this compiler build');
        }
        $typeInt = VmFilter::resolveInputType($frame->calledArgs[0], 'filter_has_var');
        // php-src Z_PARAM_STR $var_name — caller strict_types → TypeError on null (#29776).
        $keyStr = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'filter_has_var',
            1,
            'var_name'
        );
        $frame->returnVar->bool(VmFilter::hasInputVar($ctx, $typeInt, $keyStr));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('filter_has_var() requires exactly two arguments in this compiler build');
        }
        $typeVal = JitFilterInputTypeArg::lower($context, $args[0]);
        // php-src Z_PARAM_STR $var_name — caller strict_types → TypeError on null (#29776).
        // Early return after TypeError so AOT try/catch does not keep lowering into a terminated block.
        if (
            (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)
            && $context->callerStrictTypes
        ) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'filter_has_var(): Argument #2 ($var_name) must be of type string, null given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'filter_has_var_null_strict_dead');

            return JitFilter::boxedFalse($context);
        }
        $keyStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'filter_has_var',
            1,
            'var_name'
        );
        $keyVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $keyStr);

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

        $id = 'fhv'.spl_object_id($context);
        $pickPostBlock = BasicBlockHelper::append($context, 'filter_has_var_pick_post_'.$id);
        $getBlock = BasicBlockHelper::append($context, 'filter_has_var_get_'.$id);
        $postBlock = BasicBlockHelper::append($context, 'filter_has_var_post_'.$id);
        $cookieBlock = BasicBlockHelper::append($context, 'filter_has_var_cookie_'.$id);
        $envBlock = BasicBlockHelper::append($context, 'filter_has_var_env_'.$id);
        $serverBlock = BasicBlockHelper::append($context, 'filter_has_var_server_'.$id);
        $badTypeBlock = BasicBlockHelper::append($context, 'filter_has_var_bad_type_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filter_has_var_done_'.$id);

        $cookieBodyBlock = BasicBlockHelper::append($context, 'filter_has_var_cookie_body_'.$id);
        $envBodyBlock = BasicBlockHelper::append($context, 'filter_has_var_env_body_'.$id);
        $serverBodyBlock = BasicBlockHelper::append($context, 'filter_has_var_server_body_'.$id);

        $context->builder->branchIf($isGet, $getBlock, $pickPostBlock);
        $context->builder->positionAtEnd($pickPostBlock);
        $context->builder->branchIf($isPost, $postBlock, $cookieBlock);

        $context->builder->positionAtEnd($cookieBlock);
        $context->builder->branchIf($isCookie, $cookieBodyBlock, $envBlock);
        $context->builder->positionAtEnd($cookieBodyBlock);
        $cookieResult = self::hasVarInSuperglobal($context, '_COOKIE', $keyVar);
        $cookieTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($envBlock);
        $context->builder->branchIf($isEnv, $envBodyBlock, $serverBlock);
        $context->builder->positionAtEnd($envBodyBlock);
        $envResult = self::hasVarInSuperglobal($context, '_ENV', $keyVar);
        $envTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        // No INPUT_SESSION in php-src — unknown types fall through to badType (#24358).
        $context->builder->positionAtEnd($serverBlock);
        $context->builder->branchIf($isServer, $serverBodyBlock, $badTypeBlock);
        $context->builder->positionAtEnd($serverBodyBlock);
        $serverResult = self::hasVarInSuperglobal($context, '_SERVER', $keyVar);
        $serverTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($badTypeBlock);
        $badResult = JitFilter::boxedFalse($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($getBlock);
        $getResult = self::hasVarInSuperglobal($context, '_GET', $keyVar);
        $getTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($postBlock);
        $postResult = self::hasVarInSuperglobal($context, '_POST', $keyVar);
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

    private static function hasVarInSuperglobal(
        Context $context,
        string $superglobal,
        JITVariable $key
    ): Value {
        $htVar = SuperglobalInit::load($context, $superglobal);

        return (new array_key_exists())->call($context, $key, $htVar);
    }
}
