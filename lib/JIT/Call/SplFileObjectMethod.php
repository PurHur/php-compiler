<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplFileObjectJitHelper;
use PHPLLVM\Value;

/**
 * SplFileObject thin-AOT methods (#28709, #33305, #33318, #33319, #33321, #33332, #33336, #33340, ext/spl/spl_directory.c).
 */
final class SplFileObjectMethod implements Call
{
    public function __construct(private readonly string $method)
    {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplFileObject::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => SplFileObjectJitHelper::compileConstruct(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::__construct() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null
            ),
            'getfilename' => SplFileObjectJitHelper::compileGetFilename($context, $args[0]),
            'getpathname', '__tostring' => SplFileObjectJitHelper::compileGetPathname($context, $args[0]),
            'getpath' => SplFileObjectJitHelper::compileGetPath($context, $args[0]),
            // getCurrentLine is an fgets alias in php-src (zim_SplFileObject_getCurrentLine).
            'fgets', 'getcurrentline' => SplFileObjectJitHelper::compileFgets($context, $args[0]),
            'fread' => SplFileObjectJitHelper::compileFread(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fread() expects exactly 1 argument, 0 given'
                )
            ),
            'fgetc' => SplFileObjectJitHelper::compileFgetc($context, $args[0]),
            'ftell' => SplFileObjectJitHelper::compileFtell($context, $args[0]),
            'flock' => SplFileObjectJitHelper::compileFlock(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::flock() expects at least 1 argument, 0 given'
                )
            ),
            'fwrite' => SplFileObjectJitHelper::compileFwrite(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fwrite() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null
            ),
            'fputcsv' => SplFileObjectJitHelper::compileFputcsv(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fputcsv() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null,
                $args[3] ?? null,
                $args[4] ?? null,
                $args[5] ?? null
            ),
            'eof' => SplFileObjectJitHelper::compileEof($context, $args[0]),
            'rewind' => SplFileObjectJitHelper::compileRewind($context, $args[0]),
            'valid' => SplFileObjectJitHelper::compileValid($context, $args[0]),
            'current' => SplFileObjectJitHelper::compileCurrent($context, $args[0]),
            'key' => SplFileObjectJitHelper::compileKey($context, $args[0]),
            'next' => SplFileObjectJitHelper::compileNext($context, $args[0]),
            default => throw new \LogicException(
                'SplFileObject JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
