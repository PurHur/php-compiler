<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mime_content_type() — sniff MIME type from path or stream (php-src ext/standard/file.c; #6196).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/file.c PHP_FUNCTION(mime_content_type)
 */
final class mime_content_type extends Internal
{
    public function __construct()
    {
        parent::__construct('mime_content_type');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('mime_content_type() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $operand = $frame->calledArgs[0]->resolveIndirect();
        $isPath = !$operand->isStreamResource()
            && !(Variable::TYPE_INTEGER === $operand->type && VmFs::isValidHandle($operand->toInt()));
        $result = VmMime::mimeContentType($frame->calledArgs[0]);
        if (false === $result) {
            if ($isPath) {
                $path = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[0],
                    'mime_content_type',
                    0,
                    'filename_or_stream'
                );
                VmStreamOpenFailure::warnFailedToOpen($frame, 'mime_content_type', $path);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('mime_content_type() requires exactly one argument in this compiler build');
        }

        return JitMimeContentType::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'mime_content_type', 0, 'filename_or_stream')
        );
    }
}
