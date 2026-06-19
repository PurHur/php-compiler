<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getLastErrors() / DateTimeImmutable::getLastErrors() — VM (#4660, #9920). */
final class DateTimeGetLastErrors extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLastErrors');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        DateTimeSupport::writeCreateFromFormatLastErrors($frame->returnVar);
    }
}
