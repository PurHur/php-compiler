<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** file_exists() — VM via host; JIT via libc stat (issue #194). */
final class file_exists extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('file_exists() requires exactly one argument');
        }
        $path = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'file_exists',
            0,
            'filename'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(@file_exists($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('file_exists() requires exactly one argument');
        }
        $path = JitPathArg::lowerFilename($context, $args[0], 'file_exists');

        return JitStat::pathExists($context, $path);
    }
}
