<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplFileObjectJitHelper;
use PHPLLVM\Value;

/**
 * SplFileObject thin-AOT methods (#28709, #33305, #33319, ext/spl/spl_directory.c).
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
                )
            ),
            'getfilename' => SplFileObjectJitHelper::compileGetFilename($context, $args[0]),
            'getpathname', '__tostring' => SplFileObjectJitHelper::compileGetPathname($context, $args[0]),
            'getpath' => SplFileObjectJitHelper::compileGetPath($context, $args[0]),
            'rewind' => SplFileObjectJitHelper::compileRewind($context, $args[0]),
            'valid' => SplFileObjectJitHelper::compileValid($context, $args[0]),
            'current' => SplFileObjectJitHelper::compileCurrent($context, $args[0]),
            'key' => SplFileObjectJitHelper::compileKey($context, $args[0]),
            'next' => SplFileObjectJitHelper::compileNext($context, $args[0]),
            'fgets' => SplFileObjectJitHelper::compileFgets($context, $args[0]),
            'eof' => SplFileObjectJitHelper::compileEof($context, $args[0]),
            default => throw new \LogicException(
                'SplFileObject JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
