<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** tmpfile() — anonymous temp FILE* stream (ext/standard/streams.c; issue #3228). */
final class tmpfile extends Internal
{
    public function __construct()
    {
        parent::__construct('tmpfile');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30676; ext/standard/file.stub.php).
        $this->requireExactArgCount($frame, 'tmpfile', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmFs::tmpfile();
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30676).
        if (!$this->requireExactJitArgCount($context, $args, 'tmpfile', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitTmpfile::invoke($context);
    }
}
