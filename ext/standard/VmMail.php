<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * mail() sendmail transport — php-src ext/standard/mail.c `php_mail()` (#3285, #21432).
 *
 * PHP-in-PHP: popen(sendmail_path) + RFC822 envelope on stdin; no new runtime C.
 */
final class VmMail
{
    /** sysexits.h EX_OK — accepted by php_mail() after pclose(). */
    private const EX_OK = 0;

    /** sysexits.h EX_TEMPFAIL — php_mail() also treats as success. */
    private const EX_TEMPFAIL = 75;

    /** Headers that reject array values (php_mail_build_headers PHP_MAIL_BUILD_HEADER_CHECK). */
    private const STRING_ONLY_HEADERS = [
        'orig-date' => true,
        'from' => true,
        'sender' => true,
        'reply-to' => true,
        'cc' => true,
        'bcc' => true,
        'message-id' => true,
        'references' => true,
        'in-reply-to' => true,
    ];

    /**
     * Coerce mail() $additional_headers — string or array (php-src mail.c; #21432).
     *
     * @throws \TypeError
     * @throws \ValueError
     */
    public static function coerceAdditionalHeaders(Variable $arg): ?string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            $headers = $arg->toString();
            VmString::rejectNullByteBuiltinStringArg($headers, 'mail', 3, 'additional_headers');
            $headers = rtrim($headers);

            return '' === $headers ? null : $headers;
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                'mail(): Argument #4 ($additional_headers) must be of type array|string, %s given',
                self::valueTypeName($arg)
            ));
        }

        return self::buildHeadersFromArray($arg->toArray());
    }

    /**
     * php_mail_build_headers() — php-src ext/standard/mail.c (#21432).
     *
     * @throws \TypeError
     * @throws \ValueError
     */
    public static function buildHeadersFromArray(HashTable $headers): ?string
    {
        $lines = [];
        foreach ($headers->iterateKeyed(true) as [$keyVar, $val]) {
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyVar->type
                || (Variable::TYPE_STRING === $keyVar->type && ctype_digit($keyVar->toString()))) {
                $numeric = Variable::TYPE_INTEGER === $keyVar->type
                    ? (string) $keyVar->toInt()
                    : $keyVar->toString();
                throw new \TypeError(\sprintf(
                    'Header name cannot be numeric, %s given',
                    $numeric
                ));
            }
            if (Variable::TYPE_STRING !== $keyVar->type) {
                throw new \TypeError(\sprintf(
                    'Header name cannot be numeric, %s given',
                    self::valueTypeName($keyVar)
                ));
            }
            $name = $keyVar->toString();
            $lower = strtolower($name);
            if ('to' === $lower) {
                throw new \ValueError('The additional headers cannot contain the "To" header');
            }
            if ('subject' === $lower) {
                throw new \ValueError('The additional headers cannot contain the "Subject" header');
            }
            $val = $val->resolveIndirect();
            if (Variable::TYPE_STRING === $val->type) {
                $lines[] = self::formatHeaderLine($name, $val->toString());
            } elseif (Variable::TYPE_ARRAY === $val->type) {
                if (isset(self::STRING_ONLY_HEADERS[$lower])) {
                    throw new \TypeError(\sprintf(
                        'Header "%s" must be of type string, array given',
                        $lower
                    ));
                }
                foreach ($val->toArray()->iterateKeyed(true) as [$subKeyVar, $subVal]) {
                    $subKeyVar = $subKeyVar->resolveIndirect();
                    if (Variable::TYPE_STRING === $subKeyVar->type && !ctype_digit($subKeyVar->toString())) {
                        throw new \TypeError(\sprintf(
                            'Header "%s" must only contain numeric keys, "%s" found',
                            $name,
                            $subKeyVar->toString()
                        ));
                    }
                    if (Variable::TYPE_INTEGER !== $subKeyVar->type
                        && Variable::TYPE_STRING !== $subKeyVar->type) {
                        throw new \TypeError(\sprintf(
                            'Header "%s" must only contain numeric keys, "%s" found',
                            $name,
                            self::valueTypeName($subKeyVar)
                        ));
                    }
                    $subVal = $subVal->resolveIndirect();
                    if (Variable::TYPE_STRING !== $subVal->type) {
                        throw new \TypeError(\sprintf(
                            'Header "%s" must only contain values of type string, %s found',
                            $name,
                            self::valueTypeName($subVal)
                        ));
                    }
                    $lines[] = self::formatHeaderLine($name, $subVal->toString());
                }
            } else {
                throw new \TypeError(\sprintf(
                    'Header "%s" must be of type array|string, %s given',
                    $name,
                    self::valueTypeName($val)
                ));
            }
        }
        if ([] === $lines) {
            return null;
        }

        return implode("\r\n", $lines);
    }

    /**
     * php_mail_build_headers_elem() field name/value checks.
     *
     * @throws \ValueError
     */
    private static function formatHeaderLine(string $name, string $value): string
    {
        if (!self::isValidHeaderName($name)) {
            throw new \ValueError(\sprintf(
                'Header name "%s" contains invalid characters',
                $name
            ));
        }
        self::assertValidHeaderValue($name, $value);

        return $name.': '.$value;
    }

    private static function isValidHeaderName(string $name): bool
    {
        $n = \strlen($name);
        for ($i = 0; $i < $n; ++$i) {
            $ord = \ord($name[$i]);
            if ($ord < 33 || $ord > 126 || ':' === $name[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws \ValueError
     */
    private static function assertValidHeaderValue(string $name, string $value): void
    {
        $n = \strlen($value);
        $i = 0;
        while ($i < $n) {
            $ch = $value[$i];
            if ("\0" === $ch) {
                throw new \ValueError(\sprintf(
                    'Header "%s" contains NULL character that is not allowed in the header',
                    $name
                ));
            }
            if ("\r" === $ch) {
                if (($i + 1) >= $n || "\n" !== $value[$i + 1]) {
                    throw new \ValueError(\sprintf(
                        'Header "%s" contains CR character that is not allowed in the header',
                        $name
                    ));
                }
                if (($i + 2) < $n && (' ' === $value[$i + 2] || "\t" === $value[$i + 2])) {
                    $i += 3;
                    continue;
                }
                throw new \ValueError(\sprintf(
                    'Header "%s" contains CRLF characters that are used as a line separator and are not allowed in the header',
                    $name
                ));
            }
            if ("\n" === $ch) {
                if (($i + 1) < $n && (' ' === $value[$i + 1] || "\t" === $value[$i + 1])) {
                    $i += 2;
                    continue;
                }
                throw new \ValueError(\sprintf(
                    'Header "%s" contains LF character that is not allowed in the header',
                    $name
                ));
            }
            ++$i;
        }
    }

    private static function valueTypeName(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown type',
        };
    }

    /**
     * Deliver via INI `sendmail_path` (mirrored host / `-d` / PHP_COMPILER_INI_SENDMAIL_PATH).
     */
    public static function send(
        Frame $frame,
        string $to,
        string $subject,
        string $message,
        ?string $headers,
        ?string $extraParams
    ): bool {
        $to = self::scrubAddressOrSubject($to);
        $subject = self::scrubAddressOrSubject($subject);
        if (null !== $headers) {
            $headers = rtrim($headers);
            if ('' === $headers) {
                $headers = null;
            }
        }

        $sendmailPath = VmIniIntrospection::mirroredHostIniGet('sendmail_path');
        if (null === $sendmailPath || '' === $sendmailPath) {
            return false;
        }

        $cmd = $sendmailPath;
        if (null !== $extraParams && '' !== $extraParams) {
            $cmd .= ' '.VmEscapeshell::escapeshellcmd($extraParams);
        }

        $handle = VmFs::popen($cmd, 'w');
        if (false === $handle) {
            self::warn(
                $frame,
                \sprintf("mail(): Could not execute mail delivery program '%s'", $sendmailPath)
            );

            return false;
        }

        $sep = "\r\n";
        $payload = 'To: '.$to.$sep.'Subject: '.$subject.$sep;
        if (null !== $headers) {
            $payload .= $headers.$sep;
        }
        $payload .= $sep.$message.$sep;
        VmFs::fwrite($handle, $payload);
        $ret = VmFs::pclose($handle);

        if (self::EX_OK !== $ret && self::EX_TEMPFAIL !== $ret) {
            return false;
        }

        return true;
    }

    /**
     * php-src mail.c — trim trailing whitespace; replace bare control chars with space
     * while preserving RFC822 long-header CRLF+WSP folding.
     */
    private static function scrubAddressOrSubject(string $value): string
    {
        $len = \strlen($value);
        if (0 === $len) {
            return $value;
        }
        while ($len > 0 && self::isAsciiSpace($value[$len - 1])) {
            --$len;
        }
        if ($len !== \strlen($value)) {
            $value = \substr($value, 0, $len);
        }
        $out = '';
        $i = 0;
        $n = \strlen($value);
        while ($i < $n) {
            $ch = $value[$i];
            if ("\r" === $ch && ($i + 1) < $n && "\n" === $value[$i + 1]
                && ($i + 2) < $n && (' ' === $value[$i + 2] || "\t" === $value[$i + 2])) {
                $out .= "\r\n";
                $i += 2;
                while ($i < $n && (' ' === $value[$i] || "\t" === $value[$i])) {
                    $out .= $value[$i];
                    ++$i;
                }

                continue;
            }
            $ord = \ord($ch);
            if ($ord < 32 || 127 === $ord) {
                $out .= ' ';
            } else {
                $out .= $ch;
            }
            ++$i;
        }

        return $out;
    }

    private static function isAsciiSpace(string $ch): bool
    {
        return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\v" === $ch || "\f" === $ch;
    }

    private static function warn(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
