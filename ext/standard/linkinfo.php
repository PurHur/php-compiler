<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'linkinfo', 0, 'path');
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
        $path = JitStringBuiltinArg::lower($context, $args[0], 'linkinfo', 0, 'path');

        return JitLinkinfo::invoke($context, $path);
    }
}
