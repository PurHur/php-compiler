<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** function_exists() — whether a function is registered in this compile unit (issue #1216, #20360). */
final class function_exists extends Internal
{
    public function __construct()
    {
        parent::__construct('function_exists');
    }

    public function execute(Frame $frame): void
    {
        // php-src Zend/zend_builtin_functions.stub.php — ArgumentCountError (#28475).
        $this->requireExactArgCount($frame, 'function_exists', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, zend_builtin_functions.c).
        $name = VmString::trimFamilyStringArgForFrame($frame, 0, 'function_exists', 0, 'function');
        $frame->returnVar->bool(VmReflection::functionExists($ctx, $name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28475.
        if (!$this->requireExactJitArgCount($context, $args, 'function_exists', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitFunctionExists::invoke($context, $args[0]);
    }
}
