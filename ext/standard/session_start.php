<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** session_start() — resume or create file-backed $_SESSION (issues #64, #1182–#1186). */
class session_start extends Internal
{
    public const NOTICE_ALREADY_ACTIVE = 'session_start(): Ignoring session_start() because a session is already active';

    public function __construct()
    {
        parent::__construct('session_start');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('session_start() takes no arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        if (VmSession::isActive()) {
            $ctx->errors->triggerError(
                self::NOTICE_ALREADY_ACTIVE,
                ErrorReporter::E_NOTICE,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $ctx,
                $frame,
                $frame->callSiteLine
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $result = VmSession::start($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_start() takes no arguments in this compiler build');
        }

        return JitSessionStart::invoke($context);
    }
}
