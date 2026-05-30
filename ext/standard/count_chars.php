<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * count_chars() — byte-frequency histogram (PHP 8 modes 0–4; ext/standard/string.c).
 *
 * VM: {@see VmString::count_chars()}; JIT/AOT: {@see JitCountChars}.
 */
final class count_chars extends Internal
{
    public function __construct()
    {
        parent::__construct('count_chars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('count_chars() accepts one or two arguments in this compiler build');
        }
        $str = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $str->type) {
            throw new \LogicException('count_chars() argument #1 must be a string in this compiler build');
        }
        $mode = 0;
        if (2 === $argc) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \LogicException('count_chars() argument #2 must be an integer in this compiler build');
            }
            $mode = $modeVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::count_chars($str->toString(), $mode);
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $ht = new HashTable();
        $maxKey = 0;
        foreach (array_keys($result) as $byte) {
            if ($byte > $maxKey) {
                $maxKey = $byte;
            }
        }
        $ht->ensureHashSlotCapacity($maxKey);
        foreach ($result as $byte => $count) {
            $slot = new Variable();
            $slot->int((int) $count);
            $ht->addIndex((int) $byte, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('count_chars() accepts one or two arguments in this compiler build');
        }
        if (2 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('count_chars() argument #2 must be an integer in this compiler build');
        }

        $mode = 0;
        if (2 === $argc) {
            $mode = JitCountChars::compileTimeMode($context, $args[1]);
        }
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $result = VmString::count_chars($literal, $mode);
            if (\is_string($result)) {
                return JitCountChars::materializeByteString($context, $result);
            }

            return JitCountChars::materializeHistogram($context, $result);
        }

        $str = $this->jitString($context, $args[0], 'count_chars() argument #1');

        return JitCountChars::invoke($context, $str, $mode);
    }
}
