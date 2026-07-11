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
 * ob_flush() — flush active buffer without ending level (ext/standard/output.c, #3588; JIT {@see JitObFlush}).
 */
final class ob_flush extends Internal
{
    public const NO_BUFFER_NOTICE = 'ob_flush(): Failed to flush buffer. No buffer to flush';

    public function __construct()
    {
        parent::__construct('ob_flush');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'ob_flush', 0);
        if (0 === OutputBuffer::getLevel()) {
            self::emitNoBufferNotice($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null === $frame->returnVar) {
            OutputBuffer::flushBuffer();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::flushBuffer());
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
        return JitObFlush::invoke($context, ...$args);
    }
}
