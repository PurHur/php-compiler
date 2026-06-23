<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * error_log() — write message to SAPI/error log (php-src ext/standard/basic_functions.c; #3380).
 */
final class error_log extends Internal
{
    public function __construct()
    {
        parent::__construct('error_log');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'error_log() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'error_log', 0, 'message');
        $messageType = 0;
        if ($argc >= 2) {
            $messageType = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'error_log', 1, 'message_type');
        }
        $destination = null;
        if ($argc >= 3) {
            $destination = self::coerceOptionalPathArg($frame->calledArgs[2], 'error_log', 2, 'destination');
        }
        if ($argc >= 4) {
            $headersArg = $frame->calledArgs[3]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($headersArg)) {
                throw new \TypeError(\sprintf(
                    'error_log(): Argument #4 ($additional_headers) must be of type ?string, %s given',
                    EnumCaseSupport::typeNameForVariable($headersArg)
                ));
            }
            if (Variable::TYPE_NULL !== $headersArg->type) {
                VmString::coerceStringBuiltinArg($headersArg, 'error_log', 3, 'additional_headers');
            }
        }

        $ok = VmErrorLog::errorLog($messageType, $message, $destination, $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            return JitErrorLog::emitArgumentCountError(
                $context,
                \sprintf('error_log() expects between 1 and 4 arguments, %d given', $argc)
            );
        }

        $messageType = $argc >= 2 ? $args[1] : null;
        $destination = $argc >= 3 ? $args[2] : null;

        return JitErrorLog::errorLog($context, $args[0], $messageType, $destination);
    }

    private static function coerceOptionalPathArg(Variable $var, string $function, int $argIndex, string $paramName): ?string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }
}
