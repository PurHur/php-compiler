<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
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
        $receiver = DateTimeSupport::requireDateTime($frame->calledArgs[0], 'DateTime::__construct()');
        $time = 'now';
        if ($argc >= 2) {
            $timeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $timeVar->type) {
                $time = VmReflection::stringArg($frame->calledArgs[1], 'DateTime::__construct() time');
            }
        }
        $timezone = null;
        if ($argc >= 3) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone($frame->calledArgs[2], 'DateTime::__construct() timezone');
            }
        }
        DateTimeSupport::initDateTime($receiver, $time, $timezone);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
