<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * phpinfo() — runtime configuration report (ext/standard/info.c parity, #3359, #5304).
 *
 * Excess argc → Zend ArgumentCountError (#30593).
 */
final class phpinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('phpinfo');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 (#30593; ext/standard/info.c).
        $this->requireArgCountRange($frame, 'phpinfo', 0, 1);
        $argc = \count($frame->calledArgs);
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
        // Catchable ArgumentCountError under AOT try/catch (#30593 / peer #30537).
        if (!$this->requireArgCountRangeJit($context, $args, 'phpinfo', 0, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::phpinfo($context, \count($args) > 0 ? $args[0] : null);
    }
}
