<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** PhpToken::isIgnorable(): bool — VM (#6794). */
final class PhpTokenIsIgnorable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIgnorable');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('PhpToken::isIgnorable() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmPhpToken::requirePhpToken($frame->calledArgs[0], 'PhpToken::isIgnorable', 0, 'object');
        $frame->returnVar->bool(VmPhpToken::isIgnorable($entry));
    }
}
