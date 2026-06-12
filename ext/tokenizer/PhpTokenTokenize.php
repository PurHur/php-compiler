<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** PhpToken::tokenize(string $code, int $flags = 0): array — VM (#6794). */
final class PhpTokenTokenize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('tokenize');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('PhpToken::tokenize() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('PhpToken::tokenize() expects at least 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $code = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'PhpToken::tokenize', 0, 'code');
        $flags = 0;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'PhpToken::tokenize', 1, 'flags');
        }
        $tokens = VmPhpToken::tokenize($frame->vmContext, $code, $flags);
        $ht = new HashTable();
        foreach ($tokens as $token) {
            $slot = new Variable();
            $slot->object($token);
            $ht->append($slot);
        }
        $frame->returnVar->array($ht);
    }
}
