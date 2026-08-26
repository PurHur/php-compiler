<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\DirectoryIteratorJitHelper;
use PHPCompiler\VM\GlobIteratorJitHelper;
use PHPLLVM\Value;

/**
 * GlobIterator thin-AOT methods (#27422, #34993).
 *
 * php-src: ext/spl/spl_directory.c — GlobIterator inherits FilesystemIterator flags.
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
            // php-src inherits zim_FilesystemIterator_getFlags — ACE cites FilesystemIterator (#34993).
            'getflags' => $this->compileExact(
                $context,
                $args,
                'FilesystemIterator::getFlags',
                0,
                static fn () => DirectoryIteratorJitHelper::compileGetFlags(
                    $context,
                    $args[0],
                    'GlobIterator'
                )
            ),
            // php-src inherits zim_FilesystemIterator_setFlags — ACE cites FilesystemIterator (#34993).
            'setflags' => $this->compileExact(
                $context,
                $args,
                'FilesystemIterator::setFlags',
                1,
                static fn () => DirectoryIteratorJitHelper::compileSetFlags(
                    $context,
                    $args[0],
                    $args[1],
                    'GlobIterator'
                )
            ),
            default => throw new \LogicException(
                'GlobIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — $args[0] is $this (#34993).
     *
     * @param callable(): Value $compile
     */
    private function compileExact(
        Context $context,
        array $args,
        string $function,
        int $expected,
        callable $compile
    ): Value {
        $given = max(0, \count($args) - 1);
        if ($given !== $expected) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'gi_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile();
    }
}
