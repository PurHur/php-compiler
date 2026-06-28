<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_preferred_mime_name() — MIME charset label (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_preferred_mime_name extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_preferred_mime_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_preferred_mime_name() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmMbstring::coerceMbEncodingNameArg(
            $frame->calledArgs[0],
            'mb_preferred_mime_name',
            0
        );
        $mime = MbstringEncodingRegistry::preferredMimeName($encoding);
        if (false === $mime) {
            trigger_error(
                sprintf('No MIME preferred name corresponding to "%s"', $encoding),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($mime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_preferred_mime_name() JIT is not supported in this compiler build'
        );
    }
}
