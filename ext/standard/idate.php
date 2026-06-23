<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** idate() — format local date part as integer (VM VmDate; JIT IdateJitHelper, #6830, #9181). */
final class idate extends Internal
{
    private const MSG_FORMAT_ONE_CHAR = 'idate format is one char';

    private const MSG_UNRECOGNIZED = 'Unrecognized date format token';

    public function __construct()
    {
        parent::__construct('idate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('idate() requires one or two arguments');
        }
        $formatVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $formatVar->type) {
            throw new \LogicException('idate() format must be a string in this compiler build');
        }
        $format = $formatVar->toString();
        if (1 !== \strlen($format)) {
            $this->triggerWarning($frame, self::MSG_FORMAT_ONE_CHAR);
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        $timestamp = VmDate::time();
        if (2 === $argc) {
            $coerced = VmDate::coerceNullableTimestampArg($frame->calledArgs[1], 'idate', 2, 'timestamp');
            if (null !== $coerced) {
                $timestamp = $coerced;
            }
        }
        $value = VmDate::idateValue($format, $timestamp);
        if (false === $value) {
            $this->triggerWarning($frame, self::MSG_UNRECOGNIZED);
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($value);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('idate() requires one or two arguments');
        }
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = $args[1];
        }

        return JitIdate::invoke($context, $args[0], $timestamp);
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
