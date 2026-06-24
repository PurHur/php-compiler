<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** unpack() — binary decode (VM via UnpackEngine; JIT/AOT via __compiler_unpack, #3188/#5442). */
final class unpack extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('unpack() requires two or three arguments in this compiler build');
        }
        $fmt = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'unpack', 0, 'format');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'unpack', 1, 'string');
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'unpack', 3, 'offset');
            $dataLen = \strlen($data);
            if ($offset < 0 || $offset > $dataLen) {
                throw new \ValueError('unpack(): Argument #3 ($offset) must be contained in argument #2 ($data)');
            }
        }
        $result = VmPack::unpack($fmt, $data, $offset);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($frame, $result): void {
            if (false === $result) {
                if (null !== $frame->vmContext) {
                    $last = error_get_last();
                    $message = 'unpack() failed';
                    if (\is_array($last) && isset($last['message'])) {
                        $message = $last['message'];
                    }
                    $frame->vmContext->errors->triggerError(
                        $message,
                        ErrorReporter::E_WARNING,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null,
                        $frame->vmContext,
                        $frame
                    );
                }
                $ret->bool(false);

                return;
            }
            $ret->array(VmPack::importUnpackResult($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return \call_user_func_array([JitUnpack::class, 'unpack'], array_merge([$context], $args));
    }
}
