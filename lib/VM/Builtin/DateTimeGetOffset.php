<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getOffset() — VM (#14165, ext/date/php_date.c zif_date_offset_get). */
final class DateTimeGetOffset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getOffset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::getOffset() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::getOffset()',
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DateTimeSupport::dateOffsetGet($receiver));
    }
}
