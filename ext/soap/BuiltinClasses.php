<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\VM\Context;

/** Register ext/soap builtin classes (php-src ext/soap/soap.stub.php; #20037). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = \array_keys($ctx->classes);
        VmSoapClient::registerClass($ctx);
        foreach (\array_diff(\array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
