<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** basename() for path strings (subset of PHP; JIT/AOT via JitPath). */
final class basename extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/file.stub.php — ArgumentCountError (#28286).
        $this->requireArgCountRange($frame, 'basename', 1, 2);
        $argc = \count($frame->calledArgs);
        $path = VmFilestatArg::pathComponentFilenameArgForFrame($frame, 0, 'basename', 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $suffix = '';
        if (2 === $argc) {
            $suffix = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'basename', 1, 'suffix');
        }
        $frame->returnVar->string(VmString::basename($path, $suffix));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer ucwords #28317 / #28286.
        if ($argc < 1 || $argc > 2) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('basename() expects at least 1 argument, %d given', $argc)
                    : \sprintf('basename() expects at most 2 arguments, %d given', $argc)
            );

            return $unreachable;
        }
        $path = JitFilestatArg::lowerPathComponentFilename($context, $args[0], 'basename', 0, 'path');
        if (2 === $argc) {
            $suffix = JitStringBuiltinArg::lower($context, $args[1], 'basename', 1, 'suffix');

            return JitPath::basenameWithSuffix($context, $path, $suffix);
        }

        return JitPath::basename($context, $path);
    }
}
