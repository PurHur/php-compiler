<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\CompilerVersion;

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
        foreach (ImapConstants::registeredConstants() as $name => $value) {
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

        $fns = [
            new imap_open(),
            new imap_popen(),
            new imap_reopen(),
            new imap_close(),
            new imap_errors(),
            new imap_last_error(),
            new imap_num_msg(),
            new imap_num_recent(),
            new imap_uid(),
            new imap_msgno(),
            new imap_headerinfo(),
            new imap_header(),
            new imap_fetchbody(),
            new imap_body(),
            new imap_fetchtext(),
            new imap_fetchstructure(),
            new imap_fetchheader(),
            new imap_fetch_overview(),
            new imap_sort(),
            new imap_search(),
            new imap_list(),
            new imap_listmailbox(),
            new imap_lsub(),
            new imap_listsubscribed(),
            new imap_listscan(),
            new imap_scan(),
            new imap_scanmailbox(),
            new imap_subscribe(),
            new imap_unsubscribe(),
            new imap_createmailbox(),
            new imap_create(),
            new imap_deletemailbox(),
            new imap_renamemailbox(),
            new imap_rename(),
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
            new imap_mail(),
            new imap_utf7_encode(),
            new imap_utf7_decode(),
            new imap_utf8_to_mutf7(),
            new imap_mutf7_to_utf8(),
            new imap_mail_compose(),
            new imap_mail_copy(),
            new imap_mail_move(),
            new imap_timeout(),
            new imap_rfc822_write_address(),
            new imap_rfc822_parse_adrlist(),
            new imap_rfc822_parse_headers(),
            new imap_base64(),
            new imap_qprint(),
            new imap_8bit(),
            new imap_binary(),
            new imap_utf8(),
            new imap_mime_header_decode(),
        ];
        // php-src 8.3+ imap_is_open() (#27674)
        if (version_compare(CompilerVersion::languageProfileVersion(), '8.3.0', '>=')) {
            $fns[] = new imap_is_open();
        }

        return $fns;
    }
}
