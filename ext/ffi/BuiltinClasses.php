<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCompiler\VM\Context;

/** Register ext/ffi builtin classes (php-src ext/ffi/ffi.stub.php; #4420). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!FfiExtensionPolicy::advertisesClasses()) {
            return;
        }

        $before = \array_keys($ctx->classes);
        VmFFI::registerClass($ctx);
        foreach (\array_diff(\array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
