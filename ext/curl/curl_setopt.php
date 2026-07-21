<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_setopt() — CURLOPT apply (php-src ext/curl/interface.c; #6322, #21878).
 */
final class curl_setopt extends Internal
{
    private const INVALID_OPTION_VALUE_ERROR = 'curl_setopt(): Argument #2 ($option) is not a valid cURL option';

    public function __construct()
    {
        parent::__construct('curl_setopt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'curl_setopt() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_setopt', 1);
        $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'curl_setopt', 2, 'option');
        if (!CurlConstants::isValidEasyOption($option)) {
            throw new \ValueError(self::INVALID_OPTION_VALUE_ERROR);
        }
        $ok = VmCurlEasy::setopt($easy, $option, $frame->calledArgs[2], $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'curl_setopt() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        // Compile-time null/$option that coerces to an invalid CURLOPT — ValueError like
        // zif_curl_setopt (php-src ext/curl/interface.c). Enables AOT without curl_init JIT (#21878).
        $optionArg = $args[1];
        if (JITVariable::TYPE_NULL === $optionArg->type || ($optionArg->isNullConstant ?? false)) {
            return self::emitInvalidOptionValueError($context);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $optionArg->type) {
            $lit = $optionArg->compileTimeLong ?? null;
            if (\is_int($lit) && !CurlConstants::isValidEasyOption($lit)) {
                return self::emitInvalidOptionValueError($context);
            }
        }

        throw new \LogicException('curl_setopt() is not implemented for JIT in this compiler build (issue #6322)');
    }

    private static function emitInvalidOptionValueError(Context $context): Value
    {
        ExceptionBridge::registerDeclarations($context);
        ExceptionBridge::ensureLinked($context);

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'ValueError',
                self::INVALID_OPTION_VALUE_ERROR
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        TypeErrorRaise::emitValueError($context, self::INVALID_OPTION_VALUE_ERROR);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
