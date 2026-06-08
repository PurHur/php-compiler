<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pclose() — close popen pipe and return exit status (php-src ext/standard/exec.c; #6211).
 *
 * VM: {@see VmFs::pclose()}; JIT/AOT: __compiler_pclose.
 */
final class pclose extends Internal
{
    public function __construct()
    {
        parent::__construct('pclose');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('pclose() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'pclose');
        $frame->returnVar->int(VmFs::pclose($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('pclose() requires exactly one argument in this compiler build');
        }

        return JitPclose::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'pclose() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
