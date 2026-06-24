<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** stream_is_local() — VM via VmFs; JIT/AOT via __compiler_stream_is_local (issue #6173, #11358). */
final class stream_is_local extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_is_local');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_is_local() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $frame->returnVar->bool(VmStreamMeta::isLocalUri($arg->toString()));

            return;
        }
        $handle = VmStreamArg::requireStreamHandle($arg, 'stream_is_local');
        $frame->returnVar->bool(VmFs::streamIsLocal($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_is_local() requires exactly one argument in this compiler build');
        }

        return JitStreamIsLocal::invokeArg($context, $args[0]);
    }
}
