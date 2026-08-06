<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitRequestBody;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/**
 * file_get_contents() — php://input reads REQUEST_BODY via getenv (issue #289, #291, #4157).
 */
final class file_get_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(
                'file_get_contents() expects at least 1 argument, '.\max(0, $argc - 1).' given'
            );
        }
        $filename = VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            0,
            'file_get_contents',
            'filename'
        );
        if (null === $frame->returnVar) {
            return;
        }

        $useIncludePath = false;
        if (isset($frame->calledArgs[1])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'file_get_contents',
                2,
                'use_include_path'
            );
        }

        $offset = 0;
        if (isset($frame->calledArgs[3])) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'file_get_contents', 4, 'offset');
        }

        $length = null;
        if (isset($frame->calledArgs[4])) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 4, 'file_get_contents', 5, 'length');
            if (null !== $length && $length < 0) {
                throw new \ValueError(
                    'file_get_contents(): Argument #5 ($length) must be greater than or equal to 0'
                );
            }
        }

        if ('php://input' === $filename) {
            $body = Superglobals::readRequestBody();
            if (0 === $offset && null === $length) {
                $frame->returnVar->string($body);

                return;
            }
            $frame->returnVar->string(VmString::byteSlice($body, $offset, $length));

            return;
        }

        $contextVar = isset($frame->calledArgs[2]) ? $frame->calledArgs[2]->resolveIndirect() : null;

        if (!VmFsStdio::isStdioUri($filename)
            && 'php://output' !== $filename
            && 'php://input' !== $filename
            && !VmPhpMemoryStream::isSupportedUri($filename)
            && !VmPhpFilterStream::isSupportedUri($filename)
            && !VmDataUri::isDataUri($filename)
            && !VmHttpLastResponseHeaders::isHttpUrl($filename)
            && !VmOpenBasedir::check($filename, true, 'file_get_contents', $frame->vmContext, $frame)
        ) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'file_get_contents', $filename);
            $frame->returnVar->bool(false);

            return;
        }

        $data = VmFs::fileGetContents(
            $filename,
            $useIncludePath,
            $contextVar,
            $offset,
            $length,
            $frame->vmContext
        );
        if (VmHttpLastResponseHeaders::isHttpUrl($filename)) {
            VmHttpLastResponseHeaders::bindResponseHeaderToCaller($frame);
        }
        if (false === $data) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'file_get_contents', $filename);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 5) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'file_get_contents() expects at least 1 argument, '.\max(0, $argc - 1).' given'
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if ('php://input' === $literal) {
            return JitRequestBody::readPhpInput($context);
        }

        $pathStr = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'file_get_contents', 0, 'filename');
        if (1 === $argc) {
            return JitFileGetContents::invoke($context, $pathStr);
        }

        $i64 = $context->getTypeFromString('int64');
        $offset = $i64->constInt(0, false);
        $length = $i64->constInt(-1, true);
        if ($argc >= 4) {
            $offset = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[3], 'file_get_contents', 4, 'offset');
        }
        if ($argc >= 5) {
            if (JITVariable::TYPE_VALUE === $args[4]->type && $args[4]->isNullConstant) {
                $length = $i64->constInt(-1, true);
            } else {
                $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $args[4], 'file_get_contents', 5, 'length');
                JitFileGetContents::emitLengthValueErrorIfNegative($context, $length);
            }
        }

        return JitFileGetContents::invokeSlice($context, $pathStr, $offset, $length);
    }
}
