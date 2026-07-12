<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;

/** Shared VM wiring for ext/xsl class methods (#3665). */
abstract class XsltClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmXsl::requireProcessor(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            $label
        );
    }
}
