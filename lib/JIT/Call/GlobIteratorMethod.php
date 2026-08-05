<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\GlobIteratorJitHelper;
use PHPLLVM\Value;

/**
 * GlobIterator thin-AOT methods (#27422).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class GlobIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('GlobIterator::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => GlobIteratorJitHelper::compileConstruct(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'GlobIterator::__construct() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null
            ),
            'rewind' => GlobIteratorJitHelper::compileRewind($context, $args[0]),
            'valid' => GlobIteratorJitHelper::compileValid($context, $args[0]),
            'current' => GlobIteratorJitHelper::compileCurrent($context, $args[0]),
            'key' => GlobIteratorJitHelper::compileKey($context, $args[0]),
            'next' => GlobIteratorJitHelper::compileNext($context, $args[0]),
            'getfilename' => GlobIteratorJitHelper::compileGetFilename($context, $args[0]),
            'count' => GlobIteratorJitHelper::compileCount($context, $args[0]),
            default => throw new \LogicException(
                'GlobIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
