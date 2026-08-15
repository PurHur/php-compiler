<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** linkinfo() — st_dev from lstat(2) on the link (php-src ext/standard/link.c, #6083, #10294). */
final class linkinfo extends Internal
{
    private const MISSING_PATH_WARNING = 'linkinfo(): No such file or directory';

    public function execute(Frame $frame): void
    {
        // php-src link.c / basic_functions.stub.php — exactly 1 (#30553).
        $this->requireExactArgCount($frame, 'linkinfo', 1);
        // Z_PARAM_PATH: TypeError under caller strict_types; soft-null DEP+coerce otherwise (#31262 / peer readlink #30168).
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'linkinfo', 0, 'path', $frame);
        if (null === $frame->returnVar) {
            return;
        }
        $dev = VmFs::linkinfo($path);
        if (-1 === $dev && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                self::MISSING_PATH_WARNING,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        $frame->returnVar->int($dev);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30553 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'linkinfo', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        // Soft-null outside strict_types; strict → TypeError (#31262).
        // Early return after compile-time null TypeError — no linkinfo helper after abort
        // (AOT module verify: terminator mid-block; peer getopt #30358 / dirname #31210).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitFilestatArg::lowerFilename($context, $args[0], 'linkinfo', 0, 'path');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'linkinfo_null_path_te_cont');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'linkinfo', 0, 'path');

        return JitLinkinfo::invoke($context, $path);
    }
}
