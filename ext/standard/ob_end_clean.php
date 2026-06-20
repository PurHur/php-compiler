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
 * ob_end_clean() — discard active buffer and pop level (ext/standard/output.c, issue #3236; JIT {@see JitObEndClean}).
 */
final class ob_end_clean extends Internal
{
    public const NO_BUFFER_NOTICE = 'ob_end_clean(): Failed to delete buffer. No buffer to delete';

    public function __construct()
    {
        parent::__construct('ob_end_clean');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_end_clean() takes no arguments');
        }
        if (0 === OutputBuffer::getLevel()) {
            self::emitNoBufferNotice($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null === $frame->returnVar) {
            OutputBuffer::endClean();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::endClean());
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
        return JitObEndClean::invoke($context, ...$args);
    }
}
