<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** PhpToken::__toString(): string — VM (#6794). */
final class PhpTokenToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('PhpToken::__toString() called without $this');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmPhpToken::requirePhpToken($frame->calledArgs[0], 'PhpToken::__toString', 0, 'object');
        $frame->returnVar->string(VmPhpToken::readStringProperty($entry, VmPhpToken::PROP_TEXT));
    }
}
