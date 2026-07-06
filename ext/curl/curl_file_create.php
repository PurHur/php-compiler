<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_file_create() — construct CURLFile for multipart uploads (php-src ext/curl/curl_file.c; #6790).
 */
final class curl_file_create extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_file_create');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \ArgumentCountError('curl_file_create() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > 3) {
            throw new \ArgumentCountError(\sprintf(
                'curl_file_create() expects at most 3 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'curl_file_create', 0, 'filename');
        $mimeType = null;
        if (isset($frame->calledArgs[1])) {
            $mimeArg = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_NULL !== $mimeArg->type) {
                $mimeType = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'curl_file_create', 1, 'mime_type');
            }
        }
        $postedFilename = null;
        if (isset($frame->calledArgs[2])) {
            $postArg = $frame->calledArgs[2]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_NULL !== $postArg->type) {
                $postedFilename = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'curl_file_create', 2, 'posted_filename');
            }
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('curl_file_create() requires VM context');
        }
        $file = CurlFileBuiltin::create($ctx, $filename, $mimeType, $postedFilename);
        $frame->returnVar->object($file->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_file_create() is not implemented for JIT in this compiler build (issue #6790)');
    }
}
