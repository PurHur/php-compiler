<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * finfo_close() — release finfo handle (php-src ext/fileinfo/fileinfo.c; #3366).
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_close)
 */
final class finfo_close extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $finfo = VmFinfo::requireFinfoArg($frame->calledArgs[0], 'finfo_close', 0);
        $ok = VmFinfo::close($finfo);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('finfo_close() is not implemented for JIT in this compiler build (issue #3366)');
    }
}
