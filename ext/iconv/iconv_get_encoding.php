<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\JitJsonDecode;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv_get_encoding() — query iconv encoding settings (php-src ext/iconv/iconv.c; #6364, #31311).
 */
final class iconv_get_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_get_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_get_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Omitted → null sentinel ("all"). Explicit null is Z_PARAM_STR soft-null → "" → false (#31311).
        $type = null;
        if (1 === $argc) {
            $type = VmIconv::coerceEncodingArg(
                $frame->calledArgs[0],
                'iconv_get_encoding',
                0,
                'type',
                $frame
            );
        }
        $result = IconvEncodingState::getEncoding($type);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            if (\is_array($result)) {
                $ret->array(IconvEncodingState::encodingArrayToHashTable($result));

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_get_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc) {
            return self::materialize($context, IconvEncodingState::getEncoding(null));
        }

        $isNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        $rejectNull = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        if ($isNull && $rejectNull) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'iconv_get_encoding(): Argument #1 ($type) must be of type string, null given'
            );

            return self::materializeFalse($context);
        }

        $typeLit = $isNull ? '' : JitStringArg::compileTimeLiteral($args[0]);
        if (null === $typeLit) {
            throw new \LogicException(
                'iconv_get_encoding() type must be a compile-time string in this compiler build'
            );
        }
        if ($isNull) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'iconv_get_encoding',
                0,
                'type'
            );
        }

        return self::materialize($context, IconvEncodingState::getEncoding($typeLit));
    }

    /**
     * @param array{input_encoding: string, output_encoding: string, internal_encoding: string}|string|false $result
     */
    private static function materialize(Context $context, array|string|false $result): Value
    {
        if (false === $result) {
            return self::materializeFalse($context);
        }
        if (\is_string($result)) {
            return $context->builder->load($context->constantStringFromString($result));
        }

        return JitJsonDecode::materializeArray($context, $result);
    }

    private static function materializeFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }
}
