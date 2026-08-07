<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\VM\Context;

/**
 * Register MessagePack / MessagePackUnpacker (PECL msgpack/msgpack-php msgpack_class.c; #27872).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!MsgpackExtensionPolicy::advertisesExtension()) {
            return;
        }

        VmMessagePack::registerClasses($ctx);
    }
}
