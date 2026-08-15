<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tempnam() — VM via VmFs; JIT/AOT via TempnamJitHelper PHP (#15685). */
final class tempnam extends Internal
{
    public function __construct()
    {
        parent::__construct('tempnam');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 2 (#30551).
        $this->requireExactArgCount($frame, 'tempnam', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $dir = VmFsTempnam::resolveDirectoryArg($frame->calledArgs[0], $frame);
        // Z_PARAM_PATH $prefix: TypeError under caller strict_types; soft-null DEP+coerce otherwise (#31246).
        $prefix = VmFilestatArg::coerceFilenameArg(
            $frame->calledArgs[1],
            'tempnam',
            1,
            'prefix',
            $frame
        );
        $path = VmFsTempnam::invoke($dir, $prefix, $frame);
        if (false === $path) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($path);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30551 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'tempnam', 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        // Soft-null outside strict_types; strict → TypeError (#31246).
        // Early return after compile-time null TypeError — no tempnam invoke after abort
        // (AOT module verify: terminator mid-block; peer linkinfo #31262 / metaphone #31230).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerPath($context, $args[1], 'tempnam', 1, 'prefix');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'tempnam_null_prefix_te_cont');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitTempnam::invoke(
            $context,
            JitTempnam::lowerDirectory($context, $args[0]),
            JitStringBuiltinArg::lowerPath($context, $args[1], 'tempnam', 1, 'prefix')
        );
    }
}
