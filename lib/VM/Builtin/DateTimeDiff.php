<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/**
 * DateTime::diff() / DateTimeImmutable::diff() — VM (#3162, php-src zim_DateTime_diff).
 */
final class DateTimeDiff extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('diff');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('DateTime::diff() called without $this');
        }

        $receiver = DateTimeSupport::requireDateTimeLike($frame->calledArgs[0], 'DateTime::diff()');
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity 1–2 — excess says at most (not at least) (#30834; zim_DateTime_diff).
        $this->requireUserArgCountRange($frame, "{$label}::diff", 1, 2);
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        // Zend zim_DateTime_diff — Argument #1 ($targetObject) DateTimeInterface (#29868).
        $target = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[1],
            "{$label}::diff()",
            $frame->vmContext,
            1,
            'targetObject'
        );

        $absolute = false;
        if ($argc >= 3) {
            $absolute = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                "{$label}::diff",
                2,
                'absolute'
            );
        }

        $interval = DateTimeSupport::diffDateTimes(
            $receiver,
            $target,
            $absolute,
            $frame->vmContext
        );
        $frame->returnVar->object($interval);
    }
}
