<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplFileObjectJitHelper;
use PHPLLVM\Value;

/**
 * SplFileObject thin-AOT methods (#28709, ext/spl/spl_directory.c).
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
            default => throw new \LogicException(
                'SplFileObject JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
