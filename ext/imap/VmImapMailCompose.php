<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * imap_mail_compose() — MIME message from envelope + body arrays (#27765).
 *
 * Documented subset of php-src ext/imap/php_imap.c {@code PHP_FUNCTION(imap_mail_compose)}:
 * envelope keys from/to/cc/bcc/subject/date/message_id/reply_to; body parts with
 * type/subtype/contents.data; TYPEMULTIPART requires ≥2 nested parts (Zend).
 * Pure PHP — no runtime/*.c / c-client.
 */
final class VmImapMailCompose
{
    /** c-client mail.h body type codes. */
    public const TYPETEXT = 0;

    public const TYPEMULTIPART = 1;

    public const TYPEMESSAGE = 2;

    public const TYPEAPPLICATION = 3;

    public const TYPEAUDIO = 4;

    public const TYPEIMAGE = 5;

    public const TYPEVIDEO = 6;

    public const TYPEMODEL = 7;

    public const TYPEOTHER = 8;

    public const ENC7BIT = 0;

    public const ENC8BIT = 1;

    public const ENCBINARY = 2;

    public const ENCBASE64 = 3;

    public const ENCQUOTEDPRINTABLE = 4;

    public const ENCOTHER = 5;

    private const TYPE_NAMES = [
        self::TYPETEXT => 'TEXT',
        self::TYPEMULTIPART => 'MULTIPART',
        self::TYPEMESSAGE => 'MESSAGE',
        self::TYPEAPPLICATION => 'APPLICATION',
        self::TYPEAUDIO => 'AUDIO',
        self::TYPEIMAGE => 'IMAGE',
        self::TYPEVIDEO => 'VIDEO',
        self::TYPEMODEL => 'MODEL',
        self::TYPEOTHER => 'OTHER',
    ];

    private const ENC_NAMES = [
        self::ENC7BIT => '7BIT',
        self::ENC8BIT => '8BIT',
        self::ENCBINARY => 'BINARY',
        self::ENCBASE64 => 'BASE64',
        self::ENCQUOTEDPRINTABLE => 'QUOTED-PRINTABLE',
        self::ENCOTHER => 'OTHER',
    ];

    /**
     * @param array<array-key, mixed> $envelope
     * @param list<mixed>             $bodies
     */
    public static function compose(array $envelope, array $bodies): string|false
    {
        if ([] === $bodies) {
            throw new \ValueError('imap_mail_compose(): Argument #2 ($bodies) cannot be empty');
        }

        $parts = [];
        foreach ($bodies as $i => $section) {
            if (!\is_array($section)) {
                throw new \TypeError(\sprintf(
                    'imap_mail_compose(): Argument #2 ($bodies) individual body must be of type array, %s given',
                    get_debug_type($section)
                ));
            }
            if ([] === $section) {
                throw new \ValueError('imap_mail_compose(): Argument #2 ($bodies) individual body cannot be empty');
            }
            $parts[] = self::normalizePart($section);
        }

        $top = $parts[0];
        $nested = \array_slice($parts, 1);

        if (self::TYPEMULTIPART === $top['type']) {
            if (\count($nested) < 2) {
                self::warn('Cannot generate multipart e-mail without components.');

                return false;
            }
        }

        $boundary = $top['boundary'] ?? ('=_'.bin2hex(random_bytes(8)));
        $headers = self::buildEnvelopeHeaders($envelope, $top, $boundary);
        if (false === $headers) {
            return false;
        }
        $crlf = "\r\n";

        if (self::TYPEMULTIPART === $top['type']) {
            $out = $headers;
            foreach ($nested as $part) {
                $out .= '--'.$boundary.$crlf;
                $out .= self::partMiniHeader($part).$crlf;
                $out .= ($part['data'] ?? '').$crlf;
            }
            $out .= '--'.$boundary.'--'.$crlf;

            return $out;
        }

        return $headers.($top['data'] ?? '').$crlf;
    }

    /**
     * Convert a VM array Variable into a PHP array (recursive).
     *
     * @return array<array-key, mixed>
     */
    public static function variableToPhpArray(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError('Expected array');
        }

        return self::hashTableToPhp($var->toArray());
    }

    /**
     * @return array{type: int, subtype: string, data: string, boundary?: string, encoding: int}
     */
    private static function normalizePart(array $section): array
    {
        $type = isset($section['type']) ? (int) $section['type'] : self::TYPETEXT;
        $subtype = isset($section['subtype']) ? strtoupper((string) $section['subtype']) : (
            self::TYPEMULTIPART === $type ? 'MIXED' : 'PLAIN'
        );
        $data = '';
        if (isset($section['contents.data'])) {
            $data = (string) $section['contents.data'];
        } elseif (isset($section['contents'])) {
            $data = (string) $section['contents'];
        }
        $encoding = isset($section['encoding']) ? (int) $section['encoding'] : self::ENC7BIT;
        $out = [
            'type' => $type,
            'subtype' => $subtype,
            'data' => $data,
            'encoding' => $encoding,
        ];
        if (isset($section['boundary']) && \is_string($section['boundary']) && '' !== $section['boundary']) {
            $out['boundary'] = $section['boundary'];
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed>                                                             $envelope
     * @param array{type: int, subtype: string, data: string, boundary?: string, encoding: int} $top
     */
    private static function buildEnvelopeHeaders(array $envelope, array $top, string $boundary): string|false
    {
        $crlf = "\r\n";
        $lines = [];
        $map = [
            'date' => 'Date',
            'from' => 'From',
            'to' => 'To',
            'cc' => 'Cc',
            'bcc' => 'Bcc',
            'subject' => 'Subject',
            'reply_to' => 'Reply-To',
            'message_id' => 'Message-ID',
            'in_reply_to' => 'In-Reply-To',
            'remail' => 'Remailed-Date',
        ];
        foreach ($map as $key => $header) {
            if (!isset($envelope[$key])) {
                continue;
            }
            $val = (string) $envelope[$key];
            if ('' === $val) {
                continue;
            }
            if (self::hasHeaderInjection($val)) {
                self::warn('header injection attempt in '.$key);

                return false;
            }
            $lines[] = $header.': '.$val;
        }
        if (isset($envelope['custom_headers']) && \is_array($envelope['custom_headers'])) {
            foreach ($envelope['custom_headers'] as $custom) {
                $custom = (string) $custom;
                if ('' === $custom) {
                    continue;
                }
                if (self::hasHeaderInjection($custom)) {
                    self::warn('header injection attempt in custom_headers');

                    return false;
                }
                $lines[] = $custom;
            }
        }

        $lines[] = 'MIME-Version: 1.0';
        $typeName = self::TYPE_NAMES[$top['type']] ?? 'TEXT';
        if (self::TYPEMULTIPART === $top['type']) {
            $lines[] = 'Content-Type: '.$typeName.'/'.$top['subtype'].'; BOUNDARY="'.$boundary.'"';
        } else {
            $charset = 'US-ASCII';
            $lines[] = 'Content-Type: '.$typeName.'/'.$top['subtype'].'; CHARSET='.$charset;
            $encName = self::ENC_NAMES[$top['encoding']] ?? '7BIT';
            $lines[] = 'Content-Transfer-Encoding: '.$encName;
        }

        return implode($crlf, $lines).$crlf.$crlf;
    }

    /** @param array{type: int, subtype: string, data: string, encoding: int} $part */
    private static function partMiniHeader(array $part): string
    {
        $crlf = "\r\n";
        $typeName = self::TYPE_NAMES[$part['type']] ?? 'TEXT';
        $encName = self::ENC_NAMES[$part['encoding']] ?? '7BIT';
        $lines = [
            'Content-Type: '.$typeName.'/'.$part['subtype'].'; CHARSET=US-ASCII',
            'Content-Transfer-Encoding: '.$encName,
        ];

        return implode($crlf, $lines).$crlf;
    }

    private static function hasHeaderInjection(string $value): bool
    {
        return str_contains($value, "\n") || str_contains($value, "\r");
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function hashTableToPhp(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->exportKeyValuePairs(true) as [$keyVar, $valueVar]) {
            $key = self::keyToPhp($keyVar);
            $out[$key] = self::valueToPhp($valueVar);
        }

        return $out;
    }

    private static function keyToPhp(Variable $key): int|string
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            return $key->toInt();
        }

        return $key->toString();
    }

    private static function valueToPhp(Variable $var): mixed
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            Variable::TYPE_ARRAY => self::hashTableToPhp($var->toArray()),
            default => null,
        };
    }

    private static function warn(string $message): void
    {
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            @\trigger_error('imap_mail_compose(): '.$message, \E_WARNING);

            return;
        }
        $frame = $vm->builtinHandlerFrame();
        if (null === $frame) {
            $frames = $vm->context->runStackFrames();
            $frame = [] !== $frames ? $frames[0] : null;
        }
        $vm->context->errors->triggerError(
            'imap_mail_compose(): '.$message,
            ErrorReporter::E_WARNING,
            null,
            $vm->context,
            $frame
        );
    }
}
