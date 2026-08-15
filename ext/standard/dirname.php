<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
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
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31210).
            $levels = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
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
        if (1 === $argc) {
            $path = JitFilestatArg::lowerPathComponentFilename($context, $args[0], 'dirname', 0, 'path');

            return JitPath::dirname($context, $path);
        }
        // Soft-null outside strict_types; strict → TypeError (#31210).
        // Early return after compile-time null TypeError — open a dead insert block so the
        // call site can lower a discarded return without mid-block terminator (AOT verify;
        // peer settype #30506 / count #27446 / getprotobynumber #30283).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'dirname', 2, 'levels');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dirname_null_levels_te_cont');

            return $context->getTypeFromString('__string__*')->constNull();
        }
        $path = JitFilestatArg::lowerPathComponentFilename($context, $args[0], 'dirname', 0, 'path');
        // Z_PARAM_LONG with caller strict_types parity (#31210 / explode $limit).
        $levels = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'dirname', 2, 'levels');

        return JitPath::dirnameWithLevels($context, $path, $levels);
    }
}
