<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * money_format() — locale monetary formatting via strfmon(3) (#3693).
 *
 * php-src: ext/standard/formatted_print.c — PHP_FUNCTION(money_format)
 */
final class money_format extends Internal
{
    private const WARN_INVALID = 'money_format(): Invalid format string';

    public function __construct()
    {
        parent::__construct('money_format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                \sprintf('money_format() expects exactly 2 arguments, %d given', $argc)
            );
        }
        if (!VmMoneyFormat::available()) {
            throw new \Error('Call to undefined function money_format()');
        }
        $format = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'money_format',
            0,
            'format'
        );
        $value = $frame->calledArgs[1]->resolveIndirect()->toFloat();
        $result = VmMoneyFormat::format($format, $value);
        if (false === $result) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::WARN_INVALID,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('money_format() is not implemented for JIT in this compiler build (issue #3693)');
    }
}
