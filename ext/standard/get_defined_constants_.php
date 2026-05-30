<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** get_defined_constants() — runtime constant introspection (issue #3135). */
final class get_defined_constants_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_defined_constants');
    }

    public function execute(Frame $frame): void
    {
        $categorize = false;
        if (\count($frame->calledArgs) > 1) {
            throw new \LogicException('get_defined_constants() accepts at most one argument');
        }
        if (1 === \count($frame->calledArgs)) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \LogicException('get_defined_constants() categorize flag must be boolean');
            }
            $categorize = $arg->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmConstants::getDefinedConstants(VmReflection::requireContext($frame), $categorize)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('get_defined_constants() accepts at most one argument');
        }

        return JitGetDefinedConstants::invoke($context, $args[0] ?? null);
    }
}
