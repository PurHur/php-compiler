<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_encoding_aliases() — encoding alias list (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_encoding_aliases extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_encoding_aliases');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_encoding_aliases() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmMbstring::coerceMbEncodingNameArg(
            $frame->calledArgs[0],
            'mb_encoding_aliases',
            0
        );
        // php_mb_get_encoding() path — transfer encodings E_DEPRECATED on PROFILE≥8.2 (#28983).
        VmMbstring::deprecateSpecialTransferEncodingViaMbstring(
            $encoding,
            $frame,
            'mb_encoding_aliases'
        );
        $frame->returnVar->array(
            MbstringState::hashTableFromStringList(
                MbstringEncodingRegistry::aliases($encoding)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_encoding_aliases() JIT is not supported in this compiler build'
        );
    }
}
