<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::getName() — VM (#7131, php-src ext/date/php_datetime.c). */
final class DateTimeZoneGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeZone::getName() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::getName()'
        );
        // User arity excludes $this — php-src zim_DateTimeZone_getName (#30834).
        $this->requireExactUserArgCount($frame, 'DateTimeZone::getName', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DateTimeSupport::timezoneName($receiver));
    }
}
