<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** dirname() for path strings (subset of PHP; JIT/AOT via JitPath). */
final class dirname extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/file.stub.php — ArgumentCountError (#28286).
        $this->requireArgCountRange($frame, 'dirname', 1, 2);
        $argc = \count($frame->calledArgs);
        $path = VmFilestatArg::pathComponentFilenameArgForFrame($frame, 0, 'dirname', 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $levels = 1;
        if (2 === $argc) {
            $levels = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'dirname',
                2,
                'levels'
            );
        }
        $frame->returnVar->string(VmString::dirname($path, $levels));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286.
        if ($argc < 1 || $argc > 2) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('dirname() expects at least 1 argument, %d given', $argc)
                    : \sprintf('dirname() expects at most 2 arguments, %d given', $argc)
            );

            return $unreachable;
        }
        $path = JitFilestatArg::lowerPathComponentFilename($context, $args[0], 'dirname', 0, 'path');
        if (1 === $argc) {
            return JitPath::dirname($context, $path);
        }
        $levels = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'dirname', 2, 'levels');

        return JitPath::dirnameWithLevels($context, $path, $levels);
    }
}
