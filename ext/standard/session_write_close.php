<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** session_write_close() / session_commit() alias — persist $_SESSION and close (issue #1185, #12544).
 *
 * ACE messages use $this->name so session_commit(1) cites session_commit() (#30684).
 */
class session_write_close extends Internal
{
    public function __construct(string $name = 'session_write_close')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->name, 0);
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::writeClose($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->name, 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSessionWriteClose::invoke($context);
    }
}
