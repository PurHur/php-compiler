<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * finfo_close() — release finfo handle (php-src ext/fileinfo/fileinfo.c; #3366, #34688).
 *
 * JIT/AOT: thin RETURN_TRUE (php-src always returns true; GC owns the object). Peer #27196 MIME path.
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
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // php-src PHP_FUNCTION(finfo_close) — RETURN_TRUE (#34688 / FinfoConstruct thin AOT)
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return $ptr;
    }
}
