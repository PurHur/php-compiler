<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

/**
 * php-src ext/imap MINIT constants (php_imap.c; #3663, #29485).
 *
 * Named map for get_defined_constants(true)['imap'] so SA_*, SORT*, TYPE*, ENC*
 * do not fall through to the user bucket.
 */
final class ImapConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
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
            'CP_UID' => VmImapCore::CP_UID,
            'CP_MOVE' => VmImapCore::CP_MOVE,
            'IMAP_GC_ELT' => VmImapCore::IMAP_GC_ELT,
            'IMAP_GC_ENV' => VmImapCore::IMAP_GC_ENV,
            'IMAP_GC_TEXTS' => VmImapCore::IMAP_GC_TEXTS,
            'IMAP_OPENTIMEOUT' => VmImapCore::IMAP_OPENTIMEOUT,
            'IMAP_READTIMEOUT' => VmImapCore::IMAP_READTIMEOUT,
            'IMAP_WRITETIMEOUT' => VmImapCore::IMAP_WRITETIMEOUT,
            'IMAP_CLOSETIMEOUT' => VmImapCore::IMAP_CLOSETIMEOUT,
            'TYPETEXT' => VmImapMailCompose::TYPETEXT,
            'TYPEMULTIPART' => VmImapMailCompose::TYPEMULTIPART,
            'TYPEMESSAGE' => VmImapMailCompose::TYPEMESSAGE,
            'TYPEAPPLICATION' => VmImapMailCompose::TYPEAPPLICATION,
            'TYPEAUDIO' => VmImapMailCompose::TYPEAUDIO,
            'TYPEIMAGE' => VmImapMailCompose::TYPEIMAGE,
            'TYPEVIDEO' => VmImapMailCompose::TYPEVIDEO,
            'TYPEMODEL' => VmImapMailCompose::TYPEMODEL,
            'TYPEOTHER' => VmImapMailCompose::TYPEOTHER,
            'ENC7BIT' => VmImapMailCompose::ENC7BIT,
            'ENC8BIT' => VmImapMailCompose::ENC8BIT,
            'ENCBINARY' => VmImapMailCompose::ENCBINARY,
            'ENCBASE64' => VmImapMailCompose::ENCBASE64,
            'ENCQUOTEDPRINTABLE' => VmImapMailCompose::ENCQUOTEDPRINTABLE,
            'ENCOTHER' => VmImapMailCompose::ENCOTHER,
        ];
    }
}
