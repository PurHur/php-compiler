<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;

/** DateInterval::format(string $format) — VM (#7278, ext/date/php_date.c). */
final class DateIntervalFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DateInterval::format() expects exactly 1 argument');
        }
        $receiver = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'DateInterval::format()'
        );
        $format = VmReflection::stringArg($frame->calledArgs[1], 'DateInterval::format() format', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DateIntervalSupport::format($receiver, $format));
    }
}
