<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mysqli_report() — set/get error reporting mode (php-src ext/mysqli/mysqli_report.c; #21804).
 *
 * Returns the previous report mode bitmask. php-src default is
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT on PHP 8.1+.
 */
final class mysqli_report extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_report');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mysqli_report', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $flags = $frame->calledArgs[0]->resolveIndirect()->toInt();
        $previous = MysqliReportMode::getMode();
        MysqliReportMode::setMode($flags);
        // php-src returns true on success.
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_report() is not implemented for JIT in this compiler build (issue #21804)');
    }
}
