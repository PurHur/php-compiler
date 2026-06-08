<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** DateTimeImmutable::createFromFormat() — VM (#7082). */
final class DateTimeImmutableCreateFromFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromFormat');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeImmutable::createFromFormat() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \LogicException('DateTimeImmutable::createFromFormat() expects at least 2 arguments');
        }
        $format = VmReflection::stringArg($frame->calledArgs[0], 'DateTimeImmutable::createFromFormat() format', 0);
        $time = VmReflection::stringArg($frame->calledArgs[1], 'DateTimeImmutable::createFromFormat() datetime', 1);
        $timezone = null;
        if ($argc >= 3) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone(
                    $frame->calledArgs[2],
                    'DateTimeImmutable::createFromFormat() timezone'
                );
            }
        }
        $class = $frame->vmContext->classes[DateTimeSupport::CLASS_DATETIMEIMMUTABLE] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTimeImmutable is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        DateTimeSupport::initDateTimeFromFormat($entry, $format, $time, $timezone);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($entry);
    }
}
