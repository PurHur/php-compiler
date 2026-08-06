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
            'SORTDATE' => VmImapCore::SORTDATE,
            'SORTARRIVAL' => VmImapCore::SORTARRIVAL,
            'SORTFROM' => VmImapCore::SORTFROM,
            'SORTSUBJECT' => VmImapCore::SORTSUBJECT,
            'SORTTO' => VmImapCore::SORTTO,
            'SORTCC' => VmImapCore::SORTCC,
            'SORTSIZE' => VmImapCore::SORTSIZE,
            'SE_UID' => VmImapCore::SE_UID,
            'IMAP_GC_ELT' => VmImapCore::IMAP_GC_ELT,
            'IMAP_GC_ENV' => VmImapCore::IMAP_GC_ENV,
            'IMAP_GC_TEXTS' => VmImapCore::IMAP_GC_TEXTS,
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
            new imap_reopen(),
            new imap_close(),
            new imap_errors(),
            new imap_last_error(),
            new imap_num_msg(),
            new imap_num_recent(),
            new imap_uid(),
            new imap_msgno(),
            new imap_headerinfo(),
            new imap_fetchbody(),
            new imap_body(),
            new imap_fetchstructure(),
            new imap_fetchheader(),
            new imap_fetch_overview(),
            new imap_sort(),
            new imap_search(),
            new imap_list(),
            new imap_lsub(),
            new imap_listscan(),
            new imap_scan(),
            new imap_scanmailbox(),
            new imap_subscribe(),
            new imap_unsubscribe(),
            new imap_createmailbox(),
            new imap_deletemailbox(),
            new imap_renamemailbox(),
            new imap_getmailboxes(),
            new imap_getsubscribed(),
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
            new imap_setflag_full(),
            new imap_clearflag_full(),
            new imap_alerts(),
            new imap_gc(),
            new imap_thread(),
            new imap_getacl(),
            new imap_setacl(),
            new imap_get_quota(),
            new imap_get_quotaroot(),
            new imap_set_quota(),
            new imap_headers(),
            new imap_mailboxmsginfo(),
        ];
    }
}
