<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * header_remove() — remove pending response headers (issue #311, #5344).
 *
 * VM uses ResponseContext only — no host \\header_remove() delegation (bootstrap/M5).
 */
final class header_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('header_remove');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'header_remove() expects at most 1 argument, '.$argc.' given'
            );
        }
        // php-src ZEND_PARSE_PARAMETERS before sapi headers_sent (ext/standard/head.c, #25381).
        $name = null;
        if ($argc >= 1) {
            $name = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[0],
                'header_remove',
                0,
                'name'
            );
        }
        if (VmSapiHeaderGuard::headersAlreadySent($frame)) {
            VmSapiHeaderGuard::warnHeadersAlreadySent($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        ResponseContext::removeHeader($name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'header_remove() expects at most 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if (0 === $argc) {
            JitPendingHeaders::remove($context);
        } elseif (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            // php-src head.stub.php — ?string $name = null → remove all (#25381)
            JitPendingHeaders::remove($context);
        } else {
            $this->jitString($context, $args[0], 'header_remove() name');
            JitPendingHeaders::remove($context, $args[0]);
        }

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
