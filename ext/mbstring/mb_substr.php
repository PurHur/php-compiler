<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_substr() — multibyte substring (php-src ext/mbstring/mbstring.c; #3239).
 *
 * php-src mbstring.stub.php arity 4 — no user-facing $truncate (#23603).
 */
final class mb_substr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21197).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_substr', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMbstring::coerceStartArg($frame, 'mb_substr', 1);
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMbstring::coerceOptionalLengthArg($frame, 'mb_substr', 2);
        }
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_substr', 3)
            : 'UTF-8';
        $warnOnClip = CompilerVersion::supportsSubstrTruncate();
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::substr($string, $start, $length, $encoding, $warnOnClip, $frame)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_substr() is not lowered for JIT/AOT in this compiler build');
    }
}
