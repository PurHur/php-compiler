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

/** date_default_timezone_set() — set default timezone identifier (ext/date/php_date.c, #3292). */
final class date_default_timezone_set extends Internal
{
    public function __construct()
    {
        parent::__construct('date_default_timezone_set');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'date_default_timezone_set() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        // php_date.stub.php — null DEP+coerce on 8.4 forward profile (#21369, re-#20959)
        $timezone = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'date_default_timezone_set',
            0,
            'timezoneId'
        );
        $ok = VmDate::tryDefaultTimezoneSet($timezone);
        if (!$ok && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                "date_default_timezone_set(): Timezone ID '{$timezone}' is invalid",
                ErrorReporter::E_NOTICE,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'date_default_timezone_set() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitDate::defaultTimezoneSet($context, $args[0]);
    }
}
