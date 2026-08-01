<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * IMAP\Connection internal class — PHP 8.1+ ext/imap resource→object (#3663).
 *
 * php-src: ext/imap/php_imap.stub.php — final class IMAP\Connection
 */
final class VmImapConnection
{
    public const CLASS_LC = 'imap\\connection';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IMAP\\Connection');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}
