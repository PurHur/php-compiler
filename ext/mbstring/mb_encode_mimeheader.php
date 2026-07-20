<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_encode_mimeheader() — RFC 2047 encoded-word headers (php-src ext/mbstring/mbstring.c; #6038).
 */
final class mb_encode_mimeheader extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_encode_mimeheader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'mb_encode_mimeheader() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21430).
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_encode_mimeheader', 0, 'str');
        if (null === $frame->returnVar) {
            return;
        }
        $charset = 'UTF-8';
        if ($argc >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $charset = VmMbstring::coerceEncodingString($arg, 'mb_encode_mimeheader', 1);
            }
        }
        $base64 = true;
        if ($argc >= 3) {
            $base64 = VmMbstring::coerceMimeHeaderTransferEncoding(
                $frame->calledArgs[2],
                'mb_encode_mimeheader',
                2
            );
        }
        $linefeed = $argc >= 4
            ? VmMbstring::coerceMimeHeaderLinefeed($frame->calledArgs[3], 'mb_encode_mimeheader', 3)
            : "\r\n";
        $indent = $argc >= 5
            ? VmMbstring::coerceMimeHeaderIndent($frame->calledArgs[4], 'mb_encode_mimeheader', 4)
            : 0;
        $frame->returnVar->string(
            VmMbstring::encodeMimeheader($str, $charset, $base64, $linefeed, $indent)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $folded = JitMbMimeheader::tryEncodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException(
            'mb_encode_mimeheader() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
