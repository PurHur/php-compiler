<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * ob_get_flush() — return active buffer and flush to parent without ending (VM; JIT {@see JitObGetFlush}, #3753).
 *
 * php-src: ext/standard/output.c — php_ob_get_flush()
 */
final class ob_get_flush extends Internal
{
    public const NO_BUFFER_NOTICE = 'ob_get_flush(): Failed to delete and flush buffer. No buffer to delete or flush';

    public function __construct()
    {
        parent::__construct('ob_get_flush');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'ob_get_flush() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === OutputBuffer::getLevel()) {
            self::emitNoBufferNotice($frame);
            $frame->returnVar->bool(false);

            return;
        }
        $result = OutputBuffer::getFlush();
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    private static function emitNoBufferNotice(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::NO_BUFFER_NOTICE,
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObGetFlush::invoke($context, ...$args);
    }
}
