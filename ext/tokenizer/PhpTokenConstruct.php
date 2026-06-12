<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** PhpToken::__construct(int $id, string $text, int $line = -1, int $pos = -1) — VM (#6794). */
final class PhpTokenConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PhpToken::__construct() expects at least 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('PhpToken::__construct() called without $this');
        }
        $entry = $receiver->toObject();
        $id = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'PhpToken::__construct', 0, 'id');
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'PhpToken::__construct', 1, 'text');
        $line = -1;
        if (\count($frame->calledArgs) >= 4) {
            $line = VmMath::parseIntBuiltinArg($frame->calledArgs[3], 'PhpToken::__construct', 2, 'line');
        }
        $pos = -1;
        if (\count($frame->calledArgs) >= 5) {
            $pos = VmMath::parseIntBuiltinArg($frame->calledArgs[4], 'PhpToken::__construct', 3, 'pos');
        }
        $entry->getProperty(VmPhpToken::PROP_ID)->int($id);
        $entry->getProperty(VmPhpToken::PROP_TEXT)->string($text);
        $entry->getProperty(VmPhpToken::PROP_LINE)->int($line);
        $entry->getProperty(VmPhpToken::PROP_POS)->int($pos);
        $entry->constructed = true;
    }
}
