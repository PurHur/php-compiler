<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\SplFileObjectJitHelper;
use PHPLLVM\Value;

/**
 * SplFileObject / SplTempFileObject thin-AOT methods
 * (#28709, #33305, #33318, #33319, #33321, #33332, #33336, #33340, #33346, #33347, #33348,
 * #33354, #33358, #33359, #33364, #33368, #33371, #33377, #33378, #33382, #33388, #33390,
 * #33396, #33431, #34984, ext/spl/spl_directory.c).
 */
final class SplFileObjectMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly bool $tempFileObject = false,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $classLabel = $this->tempFileObject ? 'SplTempFileObject' : 'SplFileObject';
        if ([] === $args) {
            throw new \LogicException($classLabel.'::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => $this->tempFileObject
                ? SplFileObjectJitHelper::compileTempConstruct(
                    $context,
                    $args[0],
                    $args[1] ?? null
                )
                : SplFileObjectJitHelper::compileConstruct(
                    $context,
                    $args[0],
                    $args[1] ?? throw new \ArgumentCountError(
                        'SplFileObject::__construct() expects at least 1 argument, 0 given'
                    ),
                    $args[2] ?? null
                ),
            'getfilename' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getFilename',
                0,
                static fn () => SplFileObjectJitHelper::compileGetFilename($context, $args[0])
            ),
            'getpathname', '__tostring' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::'.(('__tostring' === strtolower($this->method)) ? '__toString' : 'getPathname'),
                0,
                static fn () => SplFileObjectJitHelper::compileGetPathname($context, $args[0])
            ),
            'getpath' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getPath',
                0,
                static fn () => SplFileObjectJitHelper::compileGetPath($context, $args[0])
            ),
            // getCurrentLine is an fgets alias in php-src (zim_SplFileObject_getCurrentLine).
            // php-src zim_SplFileObject_fgets — ZEND_PARSE_PARAMETERS_NONE (#30937 / #34984).
            'fgets', 'getcurrentline' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::'.(('getcurrentline' === strtolower($this->method)) ? 'getCurrentLine' : 'fgets'),
                0,
                static fn () => SplFileObjectJitHelper::compileFgets($context, $args[0])
            ),
            'fread' => SplFileObjectJitHelper::compileFread(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fread() expects exactly 1 argument, 0 given'
                )
            ),
            'fgetc' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::fgetc',
                0,
                static fn () => SplFileObjectJitHelper::compileFgetc($context, $args[0])
            ),
            'ftell' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::ftell',
                0,
                static fn () => SplFileObjectJitHelper::compileFtell($context, $args[0])
            ),
            'fstat' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::fstat',
                0,
                static fn () => SplFileObjectJitHelper::compileFstat($context, $args[0])
            ),
            'fseek' => SplFileObjectJitHelper::compileFseek(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fseek() expects at least 1 argument, 0 given'
                ),
                $args[2] ?? null
            ),
            'seek' => SplFileObjectJitHelper::compileSeek(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::seek() expects exactly 1 argument, 0 given'
                )
            ),
            'flock' => SplFileObjectJitHelper::compileFlock(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::flock() expects at least 1 argument, 0 given'
                )
            ),
            'ftruncate' => SplFileObjectJitHelper::compileFtruncate(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::ftruncate() expects exactly 1 argument, 0 given'
                )
            ),
            // php-src zim_SplFileObject_fflush — ACE cites SplFileObject even via SplTempFileObject (#30937).
            'fflush' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::fflush',
                0,
                static fn () => SplFileObjectJitHelper::compileFflush($context, $args[0])
            ),
            'fpassthru' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::fpassthru',
                0,
                static fn () => SplFileObjectJitHelper::compileFpassthru($context, $args[0])
            ),
            'setflags' => SplFileObjectJitHelper::compileSetFlags(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::setFlags() expects exactly 1 argument, 0 given'
                )
            ),
            // php-src zim_SplFileObject_getFlags — ZEND_PARSE_PARAMETERS_NONE (#34984).
            'getflags' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getFlags',
                0,
                static fn () => SplFileObjectJitHelper::compileGetFlags($context, $args[0])
            ),
            'setmaxlinelen' => SplFileObjectJitHelper::compileSetMaxLineLen(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::setMaxLineLen() expects exactly 1 argument, 0 given'
                )
            ),
            'getmaxlinelen' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getMaxLineLen',
                0,
                static fn () => SplFileObjectJitHelper::compileGetMaxLineLen($context, $args[0])
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
            'fgetcsv' => SplFileObjectJitHelper::compileFgetcsv(
                $context,
                $args[0],
                $args[1] ?? null,
                $args[2] ?? null,
                $args[3] ?? null
            ),
            'fscanf' => SplFileObjectJitHelper::compileFscanf(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::fscanf() expects at least 1 argument, 0 given'
                ),
                ...\array_slice($args, 2)
            ),
            'setcsvcontrol' => SplFileObjectJitHelper::compileSetCsvControl(
                $context,
                $args[0],
                $args[1] ?? null,
                $args[2] ?? null,
                $args[3] ?? null
            ),
            'getcsvcontrol' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getCsvControl',
                0,
                static fn () => SplFileObjectJitHelper::compileGetCsvControl($context, $args[0])
            ),
            // php-src zim_SplFileObject_eof — ZEND_PARSE_PARAMETERS_NONE (#30937 / #34984).
            'eof' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::eof',
                0,
                static fn () => SplFileObjectJitHelper::compileEof($context, $args[0])
            ),
            'haschildren' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::hasChildren',
                0,
                static fn () => SplFileObjectJitHelper::compileHasChildren($context, $args[0])
            ),
            'getchildren' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::getChildren',
                0,
                static fn () => SplFileObjectJitHelper::compileGetChildren($context, $args[0])
            ),
            'rewind' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::rewind',
                0,
                static fn () => SplFileObjectJitHelper::compileRewind($context, $args[0])
            ),
            'valid' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::valid',
                0,
                static fn () => SplFileObjectJitHelper::compileValid($context, $args[0])
            ),
            'current' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::current',
                0,
                static fn () => SplFileObjectJitHelper::compileCurrent($context, $args[0])
            ),
            'key' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::key',
                0,
                static fn () => SplFileObjectJitHelper::compileKey($context, $args[0])
            ),
            'next' => $this->compileExact(
                $context,
                $args,
                'SplFileObject::next',
                0,
                static fn () => SplFileObjectJitHelper::compileNext($context, $args[0])
            ),
            default => throw new \LogicException(
                $classLabel.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_NONE — $args[0] is $this (#30937 / #34984).
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
                'sfo_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile();
    }
}
