<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * session_start($options) for compiled JIT/AOT modules (#18457).
 *
 * SSOT: {@see SessionStartOptions}, {@see VmSession}.
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 */
final class SessionStartOptionsJitHelper
{
    public static function applyAndStart(Variable $out, Variable $options): void
    {
        $ctx = VmActiveContextJitHelper::resolve();
        $options = $options->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $options->type) {
            $out->bool(false);

            return;
        }
        $readAndClose = SessionStartOptions::applyJit($ctx, $options->toArray());
        if (VmSession::isActive()) {
            $ctx->errors->triggerError(
                session_start::NOTICE_ALREADY_ACTIVE,
                ErrorReporter::E_NOTICE,
                null,
                $ctx,
                null
            );
            $out->bool(true);

            return;
        }
        if (SapiOutput::headersSent()) {
            $ctx->errors->triggerError(
                VmSession::HEADERS_SENT_START_WARNING,
                ErrorReporter::E_WARNING,
                null,
                $ctx,
                null
            );
            $out->bool(false);

            return;
        }
        $result = VmSession::start($ctx);
        if ($readAndClose && $result) {
            VmSession::readClose();
        }
        $out->bool($result);
    }
}
