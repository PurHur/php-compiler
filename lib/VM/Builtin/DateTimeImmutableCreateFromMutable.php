<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeImmutable::createFromMutable(DateTime $object): DateTimeImmutable — VM (#6197). */
final class DateTimeImmutableCreateFromMutable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromMutable');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeImmutable::createFromMutable() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeImmutable::createFromMutable() expects exactly 1 argument');
        }
        $immutable = DateTimeSupport::createDateTimeImmutableFromMutable(
            $frame->calledArgs[0],
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($immutable);
    }
}
