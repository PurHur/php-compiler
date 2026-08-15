<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv_set_encoding() — set iconv encoding settings (php-src ext/iconv/iconv.c; #6364, #31311).
 */
final class iconv_set_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_set_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_set_encoding() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = VmIconv::coerceEncodingArg($frame->calledArgs[0], 'iconv_set_encoding', 0, 'type', $frame);
        $charset = VmIconv::coerceEncodingArg($frame->calledArgs[1], 'iconv_set_encoding', 1, 'encoding', $frame);
        if (\strlen($charset) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    \sprintf(
                        'iconv_set_encoding(): Encoding parameter exceeds the maximum allowed length of %d characters',
                        IconvConstants::ENCODING_NAME_MAX_LEN
                    ),
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $ok = IconvEncodingState::setEncoding($type, $charset);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_set_encoding() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }

        $rejectNull = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        $typeIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        $encIsNull = JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant;

        if ($rejectNull && ($typeIsNull || $encIsNull)) {
            $idx = $typeIsNull ? 0 : 1;
            $param = 0 === $idx ? 'type' : 'encoding';
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'iconv_set_encoding(): Argument #%d ($%s) must be of type string, null given',
                    $idx + 1,
                    $param
                )
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        $typeLit = $typeIsNull ? '' : JitStringArg::compileTimeLiteral($args[0]);
        $encLit = $encIsNull ? '' : JitStringArg::compileTimeLiteral($args[1]);
        if (null === $typeLit || null === $encLit) {
            throw new \LogicException(
                'iconv_set_encoding() arguments must be compile-time strings in this compiler build'
            );
        }
        if ($typeIsNull) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'iconv_set_encoding',
                0,
                'type'
            );
        }
        if ($encIsNull) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'iconv_set_encoding',
                1,
                'encoding'
            );
        }
        if (\strlen($encLit) >= IconvConstants::ENCODING_NAME_MAX_LEN) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $ok = IconvEncodingState::setEncoding($typeLit, $encLit);

        return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
    }
}
