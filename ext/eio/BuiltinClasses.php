<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\VM\Context;

/** Register ext/eio builtin classes (#6442). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!EioExtensionPolicy::advertisesExtension()) {
            return;
        }
        $before = array_keys($ctx->classes);
        VmEioRequest::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
