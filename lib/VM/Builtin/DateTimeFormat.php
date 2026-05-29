<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::format(string $format) — VM (#3072). */
final class DateTimeFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DateTime::format() expects exactly 1 argument');
        }
        $receiver = DateTimeSupport::requireDateTime($frame->calledArgs[0], 'DateTime::format()');
        $format = VmReflection::stringArg($frame->calledArgs[1], 'DateTime::format() format');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DateTimeSupport::format($receiver, $format));
    }
}
