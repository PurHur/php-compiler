<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Pure-PHP RFC822 address/header helpers (php-src ext/imap/php_imap.c; #27682).
 *
 * Approximates c-client rfc822_write_address / rfc822_parse_adrlist / rfc822_parse_msg
 * for common mail-client fixtures — no libc-client, no runtime/*.c.
 */
final class VmImapRfc822
{
    /**
     * imap_rfc822_write_address() — format mailbox/host/personal (php_imap.c).
     *
     * @return string|false
     */
    public static function writeAddress(string $mailbox, string $host, string $personal): string|false
    {
        if (\strlen($mailbox) + \strlen($host) + \strlen($personal) >= 10000) {
            throw new \Error('Address buffer overflow');
        }
        $addr = $mailbox;
        if ('' !== $host) {
            $addr .= '@'.$host;
        }
        if ('' === $personal) {
            return $addr;
        }
        if (self::personalNeedsQuotes($personal)) {
            return '"'.self::escapeQuoted($personal).'" <'.$addr.'>';
        }

        return $personal.' <'.$addr.'>';
    }

    /**
     * @return list<array{mailbox?: string, host?: string, personal?: string, adl?: string}>
     */
    public static function parseAdrlist(string $string, string $defaultHostname): array
    {
        $string = trim($string);
        if ('' === $string) {
            return [];
        }
        $parts = self::splitAddressList($string);
        $out = [];
        foreach ($parts as $part) {
            $parsed = self::parseOneAddress($part, $defaultHostname);
            if (null !== $parsed) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * Build imap_rfc822_parse_headers() stdClass (php-src _php_make_header_object).
     */
    public static function parseHeadersObject(string $headers, string $defaultHostname, Context $ctx): Variable
    {
        $map = ImapMboxEngine::parseHeaders($headers);
        self::ensureStdClass($ctx);
        $obj = new ObjectEntry($ctx->classes['stdclass']);
        $obj->constructed = true;

        $setStr = static function (string $name, string $value) use ($obj): void {
            $prop = $obj->allocateProperty($name);
            $prop->string($value);
        };

        if (isset($map['date'])) {
            $setStr('date', $map['date']);
            $setStr('Date', $map['date']);
        }
        if (isset($map['subject'])) {
            $setStr('subject', $map['subject']);
            $setStr('Subject', $map['subject']);
        }
        if (isset($map['message-id'])) {
            $setStr('message_id', $map['message-id']);
        }
        if (isset($map['in-reply-to'])) {
            $setStr('in_reply_to', $map['in-reply-to']);
        }
        if (isset($map['references'])) {
            $setStr('references', $map['references']);
        }
        if (isset($map['remail'])) {
            $setStr('remail', $map['remail']);
        }
        if (isset($map['newsgroups'])) {
            $setStr('newsgroups', $map['newsgroups']);
        }
        if (isset($map['followup-to'])) {
            $setStr('followup_to', $map['followup-to']);
        }

        foreach ([
            'to' => 'to',
            'from' => 'from',
            'cc' => 'cc',
            'bcc' => 'bcc',
            'reply-to' => 'reply_to',
            'sender' => 'sender',
            'return-path' => 'return_path',
        ] as $header => $propBase) {
            if (!isset($map[$header]) || '' === trim($map[$header])) {
                continue;
            }
            $addrs = self::parseAdrlist($map[$header], $defaultHostname);
            if ([] === $addrs) {
                continue;
            }
            $full = self::formatAddressList($addrs);
            if (null !== $full) {
                $setStr($propBase.'address', $full);
            }
            $listVar = self::addressListToVariable($addrs, $ctx);
            $listProp = $obj->allocateProperty($propBase);
            $listProp->copyFrom($listVar);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($obj);

        return $var;
    }

    /**
     * @param list<array{mailbox?: string, host?: string, personal?: string, adl?: string}> $addrs
     */
    public static function addressListToVariable(array $addrs, Context $ctx): Variable
    {
        self::ensureStdClass($ctx);
        $ht = new HashTable();
        foreach ($addrs as $addr) {
            $obj = new ObjectEntry($ctx->classes['stdclass']);
            $obj->constructed = true;
            foreach (['mailbox', 'host', 'personal', 'adl'] as $key) {
                if (!isset($addr[$key]) || '' === $addr[$key]) {
                    continue;
                }
                $prop = $obj->allocateProperty($key);
                $prop->string($addr[$key]);
            }
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return $var;
    }

    private static function ensureStdClass(Context $ctx): void
    {
        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
    }

    private static function personalNeedsQuotes(string $personal): bool
    {
        return (bool) preg_match('/[\\x00-\\x1f\\x7f"\\\\,<>()@:;]/', $personal);
    }

    private static function escapeQuoted(string $personal): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $personal);
    }

    /**
     * @return list<string>
     */
    private static function splitAddressList(string $string): array
    {
        $parts = [];
        $buf = '';
        $inQuotes = false;
        $angle = 0;
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('"' === $ch && (0 === $i || '\\' !== $string[$i - 1])) {
                $inQuotes = !$inQuotes;
                $buf .= $ch;
                continue;
            }
            if (!$inQuotes) {
                if ('<' === $ch) {
                    ++$angle;
                } elseif ('>' === $ch && $angle > 0) {
                    --$angle;
                } elseif (',' === $ch && 0 === $angle) {
                    $trim = trim($buf);
                    if ('' !== $trim) {
                        $parts[] = $trim;
                    }
                    $buf = '';
                    continue;
                }
            }
            $buf .= $ch;
        }
        $trim = trim($buf);
        if ('' !== $trim) {
            $parts[] = $trim;
        }

        return $parts;
    }

    /**
     * @return array{mailbox?: string, host?: string, personal?: string, adl?: string}|null
     */
    private static function parseOneAddress(string $part, string $defaultHostname): ?array
    {
        $part = trim($part);
        if ('' === $part) {
            return null;
        }
        $personal = null;
        $angle = null;
        if (preg_match('/^(.*?)<([^>]+)>\s*$/s', $part, $m)) {
            $personal = trim($m[1]);
            $personal = trim($personal, " \t\"");
            $angle = trim($m[2]);
        } else {
            $angle = $part;
        }
        $mailbox = $angle;
        $host = $defaultHostname;
        if (str_contains($angle, '@')) {
            $at = strrpos($angle, '@');
            $mailbox = substr($angle, 0, $at);
            $host = substr($angle, $at + 1);
        }
        $out = [];
        if ('' !== $mailbox) {
            $out['mailbox'] = $mailbox;
        }
        if ('' !== $host) {
            $out['host'] = $host;
        }
        if (null !== $personal && '' !== $personal) {
            $out['personal'] = $personal;
        }

        return [] === $out ? null : $out;
    }

    /**
     * @param list<array{mailbox?: string, host?: string, personal?: string, adl?: string}> $addrs
     */
    private static function formatAddressList(array $addrs): ?string
    {
        $chunks = [];
        foreach ($addrs as $a) {
            $written = self::writeAddress(
                $a['mailbox'] ?? '',
                $a['host'] ?? '',
                $a['personal'] ?? ''
            );
            if (\is_string($written) && '' !== $written) {
                $chunks[] = $written;
            }
        }
        if ([] === $chunks) {
            return null;
        }

        return implode(', ', $chunks);
    }
}
