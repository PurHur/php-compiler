<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/**
 * DateTime::format(string $format) — VM (#3072, #29819).
 *
 * Soft-null $format on 8.4 — Zend deprecate+coerce (ext/date/php_date.c; #21536, reverts #20693 TypeError).
 * Caller strict_types → TypeError Argument #1 ($format) (skip $this; #29819).
 */
final class DateTimeFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::format() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike($frame->calledArgs[0], 'DateTime::format()');
        $formatFn = DateTimeSupport::isDateTimeImmutable($receiver)
            ? 'DateTimeImmutable::format'
            : 'DateTime::format';
        // User arity excludes $this — php-src zim_DateTime_format (#30834).
        $this->requireExactUserArgCount($frame, $formatFn, 1);
        // Soft-null on 8.4 — Zend deprecate+coerce (#21536, reverts #20693 TypeError).
        $format = VmString::trimFamilyStringArgForFrame($frame, 1, $formatFn, 0, 'format');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DateTimeSupport::format($receiver, $format));
    }
}
