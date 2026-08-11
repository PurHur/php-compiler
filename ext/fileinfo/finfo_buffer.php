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
 * finfo_buffer() — MIME/type sniff from string (php-src ext/fileinfo/fileinfo.c; #3366).
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_buffer)
 */
final class finfo_buffer extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_buffer');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_buffer() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_buffer() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $finfo = VmFinfo::requireFinfoArg($frame->calledArgs[0], 'finfo_buffer', 0);
        $string = VmString::stringBuiltinArgForFrame($frame, 1, 'finfo_buffer', 1, 'string');
        $flags = VmFinfo::coerceFlagsArg($frame, 2, 'finfo_buffer', 3, 'flags');
        $result = VmFinfo::buffer($finfo, $string, $flags, 'finfo_buffer');
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFinfoBuffer::invokeProcedural($context, ...$args);
    }
}
