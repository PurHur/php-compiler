<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DirectoryIteratorJitHelper;
use PHPLLVM\Value;

/**
 * DirectoryIterator / FilesystemIterator / SplFileInfo thin-AOT methods (#27289 … #33283, #33289).
 *
 * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_isFile / getPathname / getSize / getMTime / getLinkTarget / …
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
            'getpathname' => DirectoryIteratorJitHelper::compileGetPathname($context, $args[0], $this->className),
            'getpath' => DirectoryIteratorJitHelper::compileGetPath($context, $args[0], $this->className),
            'getsize' => DirectoryIteratorJitHelper::compileGetSize($context, $args[0], $this->className),
            'getrealpath' => DirectoryIteratorJitHelper::compileGetRealPath($context, $args[0], $this->className),
            'getmtime' => DirectoryIteratorJitHelper::compileGetMTime($context, $args[0], $this->className),
            'getatime' => DirectoryIteratorJitHelper::compileGetATime($context, $args[0], $this->className),
            'getctime' => DirectoryIteratorJitHelper::compileGetCTime($context, $args[0], $this->className),
            'getperms' => DirectoryIteratorJitHelper::compileGetPerms($context, $args[0], $this->className),
            'getowner' => DirectoryIteratorJitHelper::compileGetOwner($context, $args[0], $this->className),
            'getgroup' => DirectoryIteratorJitHelper::compileGetGroup($context, $args[0], $this->className),
            'getinode' => DirectoryIteratorJitHelper::compileGetInode($context, $args[0], $this->className),
            'getextension' => DirectoryIteratorJitHelper::compileGetExtension($context, $args[0], $this->className),
            'getbasename' => DirectoryIteratorJitHelper::compileGetBasename(
                $context,
                $args[0],
                $this->className,
                $args[1] ?? null
            ),
            'gettype' => DirectoryIteratorJitHelper::compileGetType($context, $args[0], $this->className),
            'isfile' => DirectoryIteratorJitHelper::compileIsFile($context, $args[0], $this->className),
            'isdir' => DirectoryIteratorJitHelper::compileIsDir($context, $args[0], $this->className),
            'islink' => DirectoryIteratorJitHelper::compileIsLink($context, $args[0], $this->className),
            'getlinktarget' => DirectoryIteratorJitHelper::compileGetLinkTarget($context, $args[0], $this->className),
            'isreadable' => DirectoryIteratorJitHelper::compileIsReadable($context, $args[0], $this->className),
            'iswritable' => DirectoryIteratorJitHelper::compileIsWritable($context, $args[0], $this->className),
            'isexecutable' => DirectoryIteratorJitHelper::compileIsExecutable($context, $args[0], $this->className),
            // DirectoryIterator::__toString → filename; SplFileInfo::__toString → pathname (php-src).
            '__tostring' => 'SplFileInfo' === $this->className
                ? DirectoryIteratorJitHelper::compileGetPathname($context, $args[0], $this->className)
                : DirectoryIteratorJitHelper::compileGetFilename($context, $args[0], $this->className),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
