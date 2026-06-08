<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getMicrosecond() / DateTimeImmutable::getMicrosecond() — VM (#7082). */
final class DateTimeGetMicrosecond extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMicrosecond');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::getMicrosecond() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::getMicrosecond()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DateTimeSupport::getMicrosecond($receiver));
    }
}
