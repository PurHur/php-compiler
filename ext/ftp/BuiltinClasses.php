<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\VM\Context;

/** Register ext/ftp builtin classes (php-src ext/ftp/ftp.stub.php; #7270). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmFtpConnection::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
