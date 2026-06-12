<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** gzfile() — read gzip file into line array (ext/zlib/zlib.c parity, #4657). */
final class gzfile extends Internal
{
    public function __construct()
    {
        parent::__construct('gzfile');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzfile() expects one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzfile', 0, 'filename');
        $useIncludePath = 0;
        if (2 === $argc) {
            $useIncludePath = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'gzfile',
                2,
                'use_include_path'
            );
        }
        $lines = VmGzStream::gzfile($filename, $useIncludePath);
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
        throw new \LogicException('gzfile() is VM-only in this compiler build (issue #4657)');
    }
}
