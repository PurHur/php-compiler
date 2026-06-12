<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::createFromInterface(DateTimeInterface $object): DateTime — VM (#5936). */
final class DateTimeCreateFromInterface extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromInterface');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTime::createFromInterface() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::createFromInterface() expects exactly 1 argument');
        }
        $mutable = DateTimeSupport::createDateTimeFromInterface(
            $frame->calledArgs[0],
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($mutable);
    }
}
