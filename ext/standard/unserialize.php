<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** unserialize() — VM via VmSerialize; JIT/AOT via __compiler_unserialize (issues #1175). */
final class unserialize extends Internal
{
    public function __construct()
    {
        parent::__construct('unserialize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('unserialize() requires one to three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc > 1) {
            throw new \LogicException('unserialize() options not supported in VM in this compiler build');
        }
        $payloadVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $payloadVar->type) {
            throw new \LogicException('unserialize() first argument must be a string in this compiler build');
        }
        $decoded = VmSerialize::unserialize($payloadVar->toString());
        $frame->returnVar->copyFrom(VmSerialize::importArray($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 3) {
            throw new \LogicException('unserialize() requires one to three arguments in this compiler build');
        }
        if (\count($args) > 1) {
            throw new \LogicException('unserialize() options not supported in JIT in this compiler build');
        }

        return JitUnserialize::decodeRuntime(
            $context,
            JitStringArg::lower($context, $args[0], 'unserialize() payload')
        );
    }
}
