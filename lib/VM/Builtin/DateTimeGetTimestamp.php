<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getTimestamp() — VM (#3072). */
final class DateTimeGetTimestamp extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimestamp');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::getTimestamp() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::getTimestamp()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_getTimestamp (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::getTimestamp", 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DateTimeSupport::getTimestamp($receiver));
    }
}
