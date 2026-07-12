<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv_set_encoding() — set iconv encoding settings (php-src ext/iconv/iconv.c; #6364).
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
        $type = VmIconv::coerceEncodingArg($frame->calledArgs[0], 'iconv_set_encoding', 0, 'type');
        $charset = VmIconv::coerceEncodingArg($frame->calledArgs[1], 'iconv_set_encoding', 1, 'charset');
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
        throw new \LogicException(
            'iconv_set_encoding() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
