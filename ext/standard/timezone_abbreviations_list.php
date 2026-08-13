<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * timezone_abbreviations_list() — timelib abbreviation map (ext/date/php_date.c, #11874).
 *
 * Excess argc → Zend ArgumentCountError (#30681; php-src ext/date/php_date.c).
 */
final class timezone_abbreviations_list extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_abbreviations_list');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30681; ext/date/php_date.stub.php).
        $this->requireExactArgCount($frame, 'timezone_abbreviations_list', 0);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->copyFrom(VmDateTimeNative::timezoneAbbreviationsListVariable());
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30681).
        if (!$this->requireExactJitArgCount($context, $args, 'timezone_abbreviations_list', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitTimezoneAbbreviationsList::invoke($context);
    }
}
