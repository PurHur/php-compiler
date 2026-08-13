<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateTimeCreateArg;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;

/** DateTime::__construct(string $time = 'now', ?DateTimeZone $timezone = null) — VM (#3072). */
final class DateTimeConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('DateTime::__construct() called without $this');
        }
        // User arity excludes $this — php-src zim_DateTime___construct (#30600).
        $userArgc = $argc - 1;
        if ($userArgc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'DateTime::__construct() expects at most 2 arguments, %d given',
                $userArgc
            ));
        }
        $receiver = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'DateTime::__construct()',
            null,
            null,
            $frame->vmContext
        );
        $time = 'now';
        if (isset($frame->calledArgs[1])) {
            $time = VmDateTimeCreateArg::coerceDatetime(
                $frame,
                $frame->calledArgs[1],
                'DateTime::__construct',
                0,
                'datetime'
            );
        }
        $timezone = null;
        if (isset($frame->calledArgs[2])) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone($frame->calledArgs[2], 'DateTime::__construct() timezone');
            }
        }
        DateTimeSupport::initDateTime($receiver, $time, $timezone);
    }
}
