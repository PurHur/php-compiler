<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_send_mail() — multibyte MIME mail send (php-src ext/mbstring/mbstring.c; #6548 / #35889).
 *
 * VM: {@see VmMbstring::runSendMailBuiltin}. JIT/AOT: string arg guards + false
 * (transport follow-up; peer {@see \PHPCompiler\ext\standard\mail}).
 */
final class mb_send_mail extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_send_mail');
    }

    public function execute(Frame $frame): void
    {
        VmMbstring::runSendMailBuiltin($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 5) {
            throw new \LogicException('mb_send_mail() expects three to five arguments in this compiler build');
        }
        JitStringBuiltinArg::lower($context, $args[0], 'mb_send_mail', 0, 'to');
        JitStringBuiltinArg::lower($context, $args[1], 'mb_send_mail', 1, 'subject');
        JitStringBuiltinArg::lower($context, $args[2], 'mb_send_mail', 2, 'message');
        if (isset($args[3])) {
            JitStringBuiltinArg::lower($context, $args[3], 'mb_send_mail', 3, 'additional_headers');
        }
        if (isset($args[4])) {
            JitStringBuiltinArg::lower($context, $args[4], 'mb_send_mail', 4, 'additional_params');
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
