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
 * extension_loaded() — registered extension probe (ext/standard/info.c parity, #3204).
 *
 * Z_PARAM_STR $extension — soft-null DEP+coerce on PHP_COMPILER_PROFILE=8.4 (#21281).
 * Excess/missing argc → Zend ArgumentCountError (#30593).
 */
final class extension_loaded extends Internal
{
    public function __construct()
    {
        parent::__construct('extension_loaded');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30593; ext/standard/info.c).
        $this->requireExactArgCount($frame, 'extension_loaded', 1);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21281, ext/standard/info.c).
        $name = VmString::trimFamilyStringArgForFrame($frame, 0, 'extension_loaded', 0, 'extension');
        $frame->returnVar->bool(VmInfo::extension_loaded($name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30593 / peer #30537).
        if (!$this->requireExactJitArgCount($context, $args, 'extension_loaded', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::extension_loaded($context, $args[0]);
    }
}
