<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $filename = VmZlibArg::resolveFilenameString($frame, 'gzfile');
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzfile() expects one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $useIncludePath = $i64->constInt(0, false);
        if (2 === $argc) {
            $useIncludePath = JitLongArg::lower($context, $args[1], 'gzfile', 2, 'use_include_path');
        }

        return JitGzfile::invoke(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'gzfile', 0, 'filename'),
            $useIncludePath
        );
    }
}
