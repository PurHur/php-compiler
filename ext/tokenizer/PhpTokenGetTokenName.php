<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** PhpToken::getTokenName(): ?string — VM (#6794). */
final class PhpTokenGetTokenName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTokenName');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('PhpToken::getTokenName() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmPhpToken::requirePhpToken($frame->calledArgs[0], 'PhpToken::getTokenName', 0, 'object');
        $name = VmPhpToken::getTokenName($entry);
        if (null === $name) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($name);
        }
    }
}
