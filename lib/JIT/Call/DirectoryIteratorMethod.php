<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DirectoryIteratorJitHelper;
use PHPLLVM\Value;

/**
 * DirectoryIterator / FilesystemIterator / SplFileInfo thin-AOT methods (#27289).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class DirectoryIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $className = 'DirectoryIterator',
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->className.'::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => DirectoryIteratorJitHelper::compileConstruct(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    $this->className.'::__construct() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null,
                $this->className
            ),
            'rewind' => DirectoryIteratorJitHelper::compileRewind($context, $args[0], $this->className),
            'valid' => DirectoryIteratorJitHelper::compileValid($context, $args[0], $this->className),
            'current' => DirectoryIteratorJitHelper::compileCurrent($context, $args[0]),
            'key' => DirectoryIteratorJitHelper::compileKey($context, $args[0], $this->className),
            'next' => DirectoryIteratorJitHelper::compileNext($context, $args[0], $this->className),
            'isdot' => DirectoryIteratorJitHelper::compileIsDot($context, $args[0], $this->className),
            'getfilename' => DirectoryIteratorJitHelper::compileGetFilename($context, $args[0], $this->className),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
