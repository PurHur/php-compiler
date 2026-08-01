<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM\Context;

/** Register ext/imap builtin classes (php-src ext/imap/php_imap.stub.php; #3663). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!ImapExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        VmImapConnection::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
