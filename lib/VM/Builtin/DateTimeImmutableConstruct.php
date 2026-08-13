<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateTimeCreateArg;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;

/** DateTimeImmutable::__construct(string $time = 'now', ?DateTimeZone $timezone = null) — VM (#7082). */
final class DateTimeImmutableConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('DateTimeImmutable::__construct() called without $this');
        }
        // User arity excludes $this — php-src zim_DateTimeImmutable___construct (#30600).
        $userArgc = $argc - 1;
        if ($userArgc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'DateTimeImmutable::__construct() expects at most 2 arguments, %d given',
                $userArgc
            ));
        }
        $receiver = DateTimeSupport::requireDateTimeImmutable(
            $frame->calledArgs[0],
            'DateTimeImmutable::__construct()',
            null,
            null,
            $frame->vmContext
        );
        $time = 'now';
        if (isset($frame->calledArgs[1])) {
            $time = VmDateTimeCreateArg::coerceDatetime(
                $frame,
                $frame->calledArgs[1],
                'DateTimeImmutable::__construct',
                0,
                'datetime'
            );
        }
        $timezone = null;
        if (isset($frame->calledArgs[2])) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone(
                    $frame->calledArgs[2],
                    'DateTimeImmutable::__construct() timezone'
                );
            }
        }
        DateTimeSupport::initDateTime($receiver, $time, $timezone);
    }
}
