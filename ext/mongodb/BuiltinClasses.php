<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\VM\Context;

/** Register mongodb builtin classes (#6575, #27875). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!MongodbExtensionPolicy::advertisesExtension()) {
            return;
        }
        $before = array_keys($ctx->classes);
        require_once __DIR__.'/VmMongodb.php';
        VmMongodb::registerClasses($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
