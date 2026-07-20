<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pack() — binary string from format and values (VM via PackEngine; JIT/AOT via __compiler_pack, #5231).
 *
 * Soft-null $format on PROFILE=8.4 — Zend DEP+'' (#21478, reverts #20241 TypeError).
 */
final class pack extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'pack', 1);
        // Soft-null $format on 8.4 — Zend deprecate+coerce (#21478, reverts #20241 TypeError).
        $fmt = VmString::trimFamilyStringArgForFrame($frame, 0, 'pack', 0, 'format');
        $argc = \count($frame->calledArgs);
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $packed = VmPack::pack($fmt, $values, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($packed);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireAtLeastJitArgCount($context, $args, 'pack', 1)) {
            return $context->constantFromString('');
        }

        return \call_user_func_array([JitPack::class, 'pack'], array_merge([$context], $args));
    }
}
