<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * php-src FETCH_DEFAULT_LINK() E_DEPRECATED (ext/pgsql/pgsql.c; #31184).
 *
 * Message body matches php_error_docref: "Automatic fetching of PostgreSQL connection is deprecated"
 * (function name prefix added by the error reporter display path when present in the message).
 */
final class VmPgsqlDefaultLinkDeprecation
{
    private const MESSAGE = 'Automatic fetching of PostgreSQL connection is deprecated';

    public static function emit(?Frame $frame, string $functionName): void
    {
        $message = $functionName.'(): '.self::MESSAGE;
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated($message, $vm->context, $frame);
    }
}
