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

/**
 * unpack() — binary decode (VM via UnpackEngine; JIT/AOT via __compiler_unpack, #3188/#5442).
 *
 * Z_PARAM_STR $format: null TypeError on PHP_COMPILER_PROFILE=8.4 (#20241, pack.c).
 * $string soft-null: E_DEPRECATED + '' on 8.4 (php-src pack.c / #21246); not TypeError.
 */
final class unpack extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'unpack', 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $format — null TypeError on PROFILE=8.4 (#20241, ext/standard/pack.c).
        $fmt = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'unpack', 0, 'format');
        // $string soft-null on 8.4 (#21246) — Zend Warning on empty input after coerce, not TypeError.
        $data = VmString::trimFamilyStringArgForFrame($frame, 1, 'unpack', 1, 'string');
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'unpack', 3, 'offset');
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
        if (!$this->requireArgCountRangeJit($context, $args, 'unpack', 2, 3)) {
            return $context->constantFromBool(false);
        }

        return \call_user_func_array([JitUnpack::class, 'unpack'], array_merge([$context], $args));
    }
}
