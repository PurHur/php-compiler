<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chmod() — VM via VmFs; JIT/AOT via libc chmod(2). */
final class chmod_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chmod');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chmod() requires exactly two arguments in this compiler build');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'chmod');
        $mode = VmFilestatArg::requireIntArg($frame->calledArgs[1], 'chmod', 1, 'permissions');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::chmod($path, $mode);
        if (!$ok) {
            VmFilestatFailure::warnChmodFailed($frame, $path);
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chmod() requires exactly two arguments in this compiler build');
        }
        JitFilestatArg::guardInt($context, $args[1], 'chmod', 1, 'permissions');
        $i32 = $context->getTypeFromString('int32');
        $mode = $context->builder->truncOrBitCast(
            $context->helper->loadValue($args[1]),
            $i32
        );

        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chmod');

        return JitChmod::invoke($context, $path, $mode);
    }
}
