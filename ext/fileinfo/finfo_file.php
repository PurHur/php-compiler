<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * finfo_file() — MIME/type sniff from path (php-src ext/fileinfo/fileinfo.c; #3366).
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_file)
 */
final class finfo_file extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_file() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_file() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $finfo = VmFinfo::requireFinfoArg($frame->calledArgs[0], 'finfo_file', 0);
        $path = VmString::stringBuiltinArgForFrame($frame, 1, 'finfo_file', 1, 'filename');
        VmString::rejectEmptyBuiltinStringArg($path, 'finfo_file', 1, 'filename', true);
        $flags = VmFinfo::coerceFlagsArg($frame, 2, 'finfo_file', 3, 'flags');
        $result = VmFinfo::file($finfo, $path, $flags, $frame, 'finfo_file');
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFinfoFile::invokeProcedural($context, ...$args);
    }
}
