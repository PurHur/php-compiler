<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php_uname() — operating system identification (ext/standard/info.c parity, issue #3174). */
final class php_uname extends Internal
{
    public function __construct()
    {
        parent::__construct('php_uname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 'a';
        if (1 === $argc) {
            $mode = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'php_uname',
                0,
                'mode',
                'string',
                false
            );
        }
        $frame->returnVar->string(VmInfo::php_uname($mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        $mode = null;
        if (isset($args[0])) {
            $mode = JitStringBuiltinArg::lower(
                $context,
                $args[0],
                'php_uname',
                0,
                'mode',
                'string',
                null,
                false
            );
        }

        return JitInfo::php_uname($context, $mode);
    }
}
