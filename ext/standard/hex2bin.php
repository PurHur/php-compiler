<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hex2bin() for strings (subset of PHP; JIT/AOT via native LLVM lowering). */
final class hex2bin extends Internal
{
    private const MSG_ODD_LENGTH = 'Hexadecimal input string must have an even length';

    private const MSG_INVALID_HEX = 'Input string must be hexadecimal string';

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('hex2bin() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('hex2bin() only supports strings in this compiler build');
        }
        $data = $v->toString();
        $len = VmString::byteLength($data);
        if ($len > 0 && 0 !== ($len & 1)) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::MSG_ODD_LENGTH,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmString::hex2bin($data);
        if (false === $result) {
            if ($len > 0 && null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::MSG_INVALID_HEX,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('hex2bin() requires exactly one argument');
        }

        return JitHex2bin::convert($context, $this->jitString($context, $args[0], 'hex2bin() argument #1'));
    }

}
