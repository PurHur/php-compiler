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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hex2bin() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('hex2bin() only supports strings in this compiler build');
        }
        $strict = false;
        if (2 === $argc) {
            $strictVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $strictVar->type) {
                throw new \LogicException('hex2bin() argument #2 ($strict) must be a boolean in this compiler build');
            }
            $strict = $strictVar->toBool();
        }
        $data = $v->toString();
        $len = VmString::byteLength($data);
        if ($len > 0 && 0 !== ($len & 1)) {
            if ($strict) {
                throw new \ValueError(self::MSG_ODD_LENGTH);
            }
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::MSG_ODD_LENGTH,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        try {
            $result = VmString::hex2bin($data, $strict);
        } catch (\ValueError $e) {
            throw $e;
        }
        if (false === $result) {
            if ($strict) {
                throw new \ValueError(self::MSG_INVALID_HEX);
            }
            if ($len > 0 && null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::MSG_INVALID_HEX,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hex2bin() requires one or two arguments');
        }
        $strict = null;
        if (2 === $argc) {
            $strict = $this->jitBool($context, $args[1], 'hex2bin() argument #2 ($strict)');
        }

        return JitHex2bin::convert($context, $this->jitString($context, $args[0], 'hex2bin() argument #1'), $strict);
    }

}
