<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getLastErrors() / DateTimeImmutable::getLastErrors() — VM (#4660, #9920). */
final class DateTimeGetLastErrors extends VmClassMethod
{
    public function __construct(
        private readonly string $className = 'DateTime',
    ) {
        parent::__construct('getLastErrors');
    }

    public function execute(Frame $frame): void
    {
        // Static — calledArgs has no $this (php-src zim_DateTime_getLastErrors; #30991).
        $this->requireExactArgCount($frame, "{$this->className}::getLastErrors", 0);
        if (null === $frame->returnVar) {
            return;
        }
        DateTimeSupport::writeCreateFromFormatLastErrors($frame->returnVar);
    }
}
