<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** phpinfo() — runtime configuration report (ext/standard/info.c parity, #3359, #5304). */
final class phpinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('phpinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('phpinfo() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $flags = VmInfo::INFO_ALL;
        if (1 === $argc) {
            $flags = VmInfo::resolvePhpinfoFlagsArg($frame->calledArgs[0]);
        }
        $frame->returnVar->bool(VmInfo::phpinfo($flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'phpinfo() accepts at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::phpinfo($context, $argc > 0 ? $args[0] : null);
    }
}
