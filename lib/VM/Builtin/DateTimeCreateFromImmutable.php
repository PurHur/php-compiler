<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::createFromImmutable(DateTimeImmutable $object): DateTime — VM (#6518). */
final class DateTimeCreateFromImmutable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromImmutable');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTime::createFromImmutable() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::createFromImmutable() expects exactly 1 argument');
        }
        $mutable = DateTimeSupport::createDateTimeFromImmutable(
            $frame->calledArgs[0],
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($mutable);
    }
}
