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
        if (!ImapExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach ([
            'SA_MESSAGES' => VmImapCore::SA_MESSAGES,
            'SA_RECENT' => VmImapCore::SA_RECENT,
            'SA_UNSEEN' => VmImapCore::SA_UNSEEN,
            'SA_UIDNEXT' => VmImapCore::SA_UIDNEXT,
            'SA_UIDVALIDITY' => VmImapCore::SA_UIDVALIDITY,
            'SA_ALL' => VmImapCore::SA_ALL,
        ] as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
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
            new imap_append(),
            new imap_savebody(),
            new imap_bodystruct(),
            new imap_fetchmime(),
            new imap_delete(),
            new imap_undelete(),
            new imap_expunge(),
            new imap_ping(),
            new imap_check(),
            new imap_status(),
        ];
    }
}
