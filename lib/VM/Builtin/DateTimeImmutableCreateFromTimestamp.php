<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ObjectEntry;

/** DateTimeImmutable::createFromTimestamp(int|float $timestamp): static — VM (#5973, #9984, ext/date/php_date.c). */
final class DateTimeImmutableCreateFromTimestamp extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromTimestamp');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeImmutable::createFromTimestamp() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeImmutable::createFromTimestamp() expects exactly 1 argument');
        }
        $timestamp = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0],
            'DateTimeImmutable::createFromTimestamp',
            1,
            'timestamp'
        );
        $class = $frame->vmContext->classes[DateTimeSupport::CLASS_DATETIMEIMMUTABLE] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTimeImmutable is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        DateTimeSupport::initDateTimeFromTimestamp($entry, $timestamp);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($entry);
    }
}
