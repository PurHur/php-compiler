<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'file_get_contents',
            0,
            'filename'
        );
        if (null === $frame->returnVar) {
            return;
        }

        $useIncludePath = false;
        if ($argc >= 2) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'file_get_contents',
                2,
                'use_include_path'
            );
        }

        $offset = 0;
        if ($argc >= 4) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[3]->resolveIndirect(),
                'file_get_contents',
                4,
                'offset'
            );
        }

        $length = null;
        if ($argc >= 5) {
            $lengthVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthVar->type) {
                $length = VmMath::parseIntBuiltinArg(
                    $lengthVar,
                    'file_get_contents',
                    5,
                    'length'
                );
                if ($length < 0) {
                    throw new \ValueError(
                        'file_get_contents(): Argument #5 ($length) must be greater than or equal to 0'
                    );
                }
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

        $data = VmFs::fileGetContents(
            $filename,
            $useIncludePath,
            $argc >= 3 ? $frame->calledArgs[2]->resolveIndirect() : null,
            $offset,
            $length,
            $frame->vmContext
        );
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

        $pathStr = JitStringBuiltinArg::lower($context, $args[0], 'file_get_contents', 0, 'filename');
        if (1 === $argc) {
            return JitFileGetContents::invoke($context, $pathStr);
        }

        $i64 = $context->getTypeFromString('int64');
        $offset = $i64->constInt(0, false);
        $length = $i64->constInt(-1, true);
        if ($argc >= 4) {
            $offset = JitLongArg::lower($context, $args[3], 'file_get_contents offset');
        }
        if ($argc >= 5) {
            if (JITVariable::TYPE_VALUE === $args[4]->type && $args[4]->isNullConstant) {
                $length = $i64->constInt(-1, true);
            } else {
                $length = JitLongArg::lower($context, $args[4], 'file_get_contents length');
                JitFileGetContents::emitLengthValueErrorIfNegative($context, $length);
            }
        }

        return JitFileGetContents::invokeSlice($context, $pathStr, $offset, $length);
    }
}
