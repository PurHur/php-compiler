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
    private const MSG_FORMAT_ONE_CHAR = 'idate(): idate format is one char';

    private const MSG_UNRECOGNIZED = 'idate(): Unrecognized date format token';

    public function __construct()
    {
        parent::__construct('idate');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 — #30543 (ext/date/php_date.c).
        $this->requireArgCountRange($frame, 'idate', 1, 2);
        $argc = \count($frame->calledArgs);
        $format = self::resolveFormatArg($frame);
        if (1 !== \strlen($format)) {
            $this->triggerWarning($frame, self::MSG_FORMAT_ONE_CHAR);
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        $timestamp = VmDate::time();
        if (2 === $argc) {
            $coerced = VmDate::coerceNullableTimestampArgForFrame($frame, 1, 'idate', 2, 'timestamp');
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
        // Catchable ArgumentCountError under AOT try/catch (#30543).
        if (!$this->requireArgCountRangeJit($context, $args, 'idate', 1, 2)) {
            return $context->constantFromInteger(0, 'int64');
        }
        $argc = \count($args);
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = $args[1];
        }

        return JitIdate::invoke($context, $args[0], $timestamp);
    }

    private static function resolveFormatArg(Frame $frame): string
    {
        // Soft-null on 8.4 — Zend deprecate+coerce (ext/date/php_date.c; #21491, reverts #20227 TypeError).
        return VmString::trimFamilyStringArgForFrame($frame, 0, 'idate', 0, 'format');
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
