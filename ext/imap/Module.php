<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * imap extension module entry (php-src ext/imap/php_imap.c; #3663).
 *
 * Phase-1 procedural surface via pure-PHP local mbox + remote-fail parity.
 * Advertise logical {@code imap} when {@see ImapExtensionPolicy::advertisesExtension()}.
 * JIT/AOT: VM-only v1 (call() throws LogicException).
 */
class Module extends ModuleAbstract
{
    private const IMAP_VERSION = '8.2.0';

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return self::IMAP_VERSION;
    }

    public function getFunctions(): array
    {
        if (!ImapExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new imap_open(),
            new imap_close(),
            new imap_errors(),
            new imap_last_error(),
            new imap_num_msg(),
            new imap_headerinfo(),
            new imap_fetchbody(),
            new imap_search(),
            new imap_list(),
            new imap_lsub(),
            new imap_subscribe(),
            new imap_unsubscribe(),
            new imap_createmailbox(),
            new imap_deletemailbox(),
            new imap_renamemailbox(),
            new imap_getmailboxes(),
        ];
    }
}
