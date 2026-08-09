<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * FTP\Connection internal class — PHP 8.4 ext/ftp resource→object (#7270, #3353).
 *
 * php-src: ext/ftp/ftp.stub.php — final class FTP\Connection
 */
final class VmFtpConnection
{
    public const CLASS_LC = 'ftp\\connection';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('FTP\\Connection');
        $entry->isInternal = true;
        // php-src `final class Connection` (ext/ftp/ftp.stub.php; #28403).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}
