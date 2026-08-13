<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** disk_total_space() — VM via VmFsDiskNative (statvfs FFI or VmFsDiskPure); JIT/AOT via JitStat (php-src filestat.c, #8989). */
final class disk_total_space extends Internal
{
    public function __construct(string $name = 'disk_total_space')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30552).
        $this->requireExactArgCount($frame, $fn, 1);
        $resolved = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (InternalStrictArg::isCallerStrict($frame)
                || VmString::requiresForwardProfileStrictStringNull()) {
                throw new \TypeError(
                    $fn.'(): Argument #1 ($directory) must be of type string, null given'
                );
            }
            if (null === $frame->returnVar) {
                return;
            }
            // php-src filestat.c — null directory returns false without warning (#12619, #12788).
            $frame->returnVar->bool(false);

            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $fn, 0, 'directory');
        $result = VmFs::diskTotalSpace($path);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            // php-src filestat.c — empty directory returns false without warning (#18387).
            if ('' !== $path) {
                VmFilestatFailure::warnNoSuchFile($frame, $fn);
            }
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->float($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        // Catchable ArgumentCountError under AOT try/catch (#30552 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, $fn, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitDiskPath::lowerDiskSpaceBoxed($context, $args[0], $fn, false);
    }
}
