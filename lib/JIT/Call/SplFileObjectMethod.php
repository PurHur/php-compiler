<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplFileObjectJitHelper;
use PHPLLVM\Value;

/**
 * SplFileObject thin-AOT methods (#28709, #33305, #33318, #33319, #33321, #33332, #33336, #33340, #33346, #33347, #33348, #33354, #33358, #33359, #33364, #33368, #33371, #33377, #33378, #33382, #33388, ext/spl/spl_directory.c).
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
            'fstat' => SplFileObjectJitHelper::compileFstat($context, $args[0]),
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
            'fflush' => SplFileObjectJitHelper::compileFflush($context, $args[0]),
            'fpassthru' => SplFileObjectJitHelper::compileFpassthru($context, $args[0]),
            'setflags' => SplFileObjectJitHelper::compileSetFlags(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::setFlags() expects exactly 1 argument, 0 given'
                )
            ),
            'getflags' => SplFileObjectJitHelper::compileGetFlags($context, $args[0]),
            'setmaxlinelen' => SplFileObjectJitHelper::compileSetMaxLineLen(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFileObject::setMaxLineLen() expects exactly 1 argument, 0 given'
                )
            ),
            'getmaxlinelen' => SplFileObjectJitHelper::compileGetMaxLineLen($context, $args[0]),
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
            'getcsvcontrol' => SplFileObjectJitHelper::compileGetCsvControl($context, $args[0]),
            'eof' => SplFileObjectJitHelper::compileEof($context, $args[0]),
            'haschildren' => SplFileObjectJitHelper::compileHasChildren($context, $args[0]),
            'getchildren' => SplFileObjectJitHelper::compileGetChildren($context, $args[0]),
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
