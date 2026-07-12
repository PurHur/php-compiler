<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** uploadprogress_get_contents() — PECL uploadprogress (ext/uploadprogress/uploadprogress.c; #6386). */
final class uploadprogress_get_contents extends Internal
{
    public function __construct()
    {
        parent::__construct('uploadprogress_get_contents');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'uploadprogress_get_contents() expects at least 2 arguments, '.\max(0, $argc - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmUploadprogress::getContentsEnabled()) {
            $frame->vmContext->errors->triggerError(
                'uploadprogress_get_contents(): this function is disabled; set uploadprogress.get_contents = On to enable it',
                ErrorReporter::E_WARNING,
                null,
                $frame->vmContext,
                $frame
            );
            $frame->returnVar->bool(false);

            return;
        }
        $identifier = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'uploadprogress_get_contents',
            1,
            'identifier'
        );
        $fieldName = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'uploadprogress_get_contents',
            2,
            'fieldname'
        );
        $maxLength = -1;
        if ($argc >= 3) {
            $maxVar = $frame->calledArgs[2]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \TypeError(
                    'uploadprogress_get_contents(): Argument #3 ($maxlen) must be of type int, '
                    .match ($maxVar->type) {
                        \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                        \PHPCompiler\VM\Variable::TYPE_BOOLEAN => 'bool',
                        \PHPCompiler\VM\Variable::TYPE_FLOAT => 'float',
                        \PHPCompiler\VM\Variable::TYPE_STRING => 'string',
                        \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                        \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                    .' given'
                );
            }
            $maxLength = $maxVar->toInt();
            if ($maxLength < 0) {
                $frame->vmContext->errors->triggerError(
                    'uploadprogress_get_contents(): length must be greater than or equal to zero',
                    ErrorReporter::E_WARNING,
                    null,
                    $frame->vmContext,
                    $frame
                );
                $frame->returnVar->bool(false);

                return;
            }
        }
        $contents = VmUploadprogress::getContents($identifier, $fieldName, $maxLength);
        if (false === $contents) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($contents);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'uploadprogress_get_contents() is not implemented for JIT in this compiler build (issue #6386)'
        );
    }
}
