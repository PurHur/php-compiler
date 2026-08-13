<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DirectoryJitHelper;
use PHPLLVM\Value;

/**
 * Directory::{__construct,read,rewind,close} thin-AOT methods (#30757).
 *
 * php-src: ext/standard/dir.c
 */
final class DirectoryMethod implements Call
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
        if ([] === $args && '__construct' !== strtolower($this->method)) {
            throw new \LogicException('Directory::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => DirectoryJitHelper::compileConstruct($context),
            'read' => DirectoryJitHelper::compileRead($context, $args[0]),
            'rewind' => DirectoryJitHelper::compileRewind($context, $args[0]),
            'close' => DirectoryJitHelper::compileClose($context, $args[0]),
            default => throw new \LogicException(
                'Directory JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
