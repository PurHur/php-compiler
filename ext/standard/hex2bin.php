<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hex2bin() for strings (subset of PHP; JIT/AOT via native LLVM lowering). */
final class hex2bin extends Internal
{
    private const MSG_ODD_LENGTH = 'hex2bin(): Hexadecimal input string must have an even length';

    private const MSG_INVALID_HEX = 'hex2bin(): Input string must be hexadecimal string';

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hex2bin() requires one or two arguments');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hex2bin', 0, 'string');
        $strict = false;
        if (2 === $argc) {
            $strictVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $strictVar->type) {
                throw new \LogicException('hex2bin() argument #2 ($strict) must be a boolean in this compiler build');
            }
            $strict = $strictVar->toBool();
        }
        $len = VmString::byteLength($data);
        if ($len > 0 && 0 !== ($len & 1)) {
            if ($strict) {
                throw new \Error(self::MSG_ODD_LENGTH);
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
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        $result = VmString::hex2bin($data, $strict);
        if (false === $result) {
            if ($strict) {
                throw new \Error(self::MSG_INVALID_HEX);
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
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($result): void {
            $ret->string($result);
        });
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

        return JitHex2bin::convert(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hex2bin', 0, 'string'),
            $strict
        );
    }

}
