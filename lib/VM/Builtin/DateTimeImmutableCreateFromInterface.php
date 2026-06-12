<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeImmutable::createFromInterface(DateTimeInterface $object): DateTimeImmutable — VM (#5936). */
final class DateTimeImmutableCreateFromInterface extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromInterface');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeImmutable::createFromInterface() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeImmutable::createFromInterface() expects exactly 1 argument');
        }
        $immutable = DateTimeSupport::createDateTimeImmutableFromInterface(
            $frame->calledArgs[0],
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($immutable);
    }
}
