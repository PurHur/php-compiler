<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * ob_start() — begin output buffering (VM; JIT scaffold {@see JitObStart}, #118, #1056).
 *
 * Excess argc → Zend ArgumentCountError (#30508; php-src ext/standard/output.c).
 * Z_PARAM_LONG $chunk_size / $flags — strict_types null → TypeError (#31228).
 */
final class ob_start extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_start');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: at most 3 (callback, chunk_size, flags) — #30508.
        $this->requireAtMostArgCount($frame, 'ob_start', 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_LONG before starting the buffer (output.c / basic_functions.stub.php; #31228).
        if ($argc >= 2) {
            VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'ob_start',
                2,
                'chunk_size'
            );
        }
        if ($argc >= 3) {
            VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'ob_start',
                3,
                'flags'
            );
        }
        $handler = null;
        if ($argc >= 1) {
            $handler = VmObOutput::resolveHandler($frame);
        }
        OutputBuffer::start($handler);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 3) {
            // Catchable ArgumentCountError under AOT try/catch (#30508 / peer #28229).
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('ob_start() expects at most 3 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'ob_start_argc_cont');

            return $context->constantFromBool(false);
        }

        return JitObStart::invoke($context, ...$args);
    }
}
