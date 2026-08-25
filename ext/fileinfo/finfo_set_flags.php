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
 * finfo_set_flags() — update sniff flags (php-src ext/fileinfo/fileinfo.c; #3366, #34688).
 *
 * JIT/AOT: thin RETURN_TRUE — MIME sniff via FinfoFileRuntime ignores flags today (#27196).
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_set_flags)
 */
final class finfo_set_flags extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_set_flags');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_set_flags() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $finfo = VmFinfo::requireFinfoArg($frame->calledArgs[0], 'finfo_set_flags', 0);
        $flags = VmFinfo::coerceFlagsArg($frame, 1, 'finfo_set_flags', 2, 'flags');
        $ok = VmFinfo::setFlags($finfo, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_set_flags() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        // php-src PHP_FUNCTION(finfo_set_flags) — RETURN_TRUE on success (#34688 / FinfoConstruct)
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return $ptr;
    }
}
