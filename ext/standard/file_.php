<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * file() — read path into array of lines (ext/standard/file.c; issue #3765).
 */
final class file_ extends Internal
{
    public function __construct()
    {
        parent::__construct('file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('file() requires one or two arguments in this compiler build');
        }
        $path = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[0], 'file');
        $flags = 0;
        if (2 === $argc) {
            $flags = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'file', 1, 'flags');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $lines = VmFs::file($path, $flags);
        if (false === $lines) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($lines as $line) {
            $value = new Variable();
            $value->string($line);
            $ht->append($value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('file() requires one or two arguments in this compiler build');
        }
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'file');
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (2 === $argc) {
            $flags = JitLongArg::lower($context, $args[1], 'file() flags');
        }

        return JitFile::invoke($context, $path, $flags);
    }
}
