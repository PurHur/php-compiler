<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * IMAP session state — open/close + mbox helpers (php-src ext/imap/php_imap.c; #3663).
 *
 * Local path / mbox file → pure-PHP {@see ImapMboxEngine}. Remote {@code {host}…}
 * attempts a short TCP connect; failure returns false + warning + error stack
 * (Zend parity). Full IMAP protocol / c-client is out of v1 scope — no runtime/*.c.
 */
final class VmImapCore
{
    /** @var list<string> */
    private static array $errors = [];

    private static ?string $lastError = null;

    /**
     * @var array<int, array{
     *     mailbox: string,
     *     closed: bool,
     *     messages: list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>
     * }>
     */
    private static array $state = [];

    public static function clearErrors(): void
    {
        self::$errors = [];
        self::$lastError = null;
    }

    /**
     * @return list<string>|false
     */
    public static function errors(): array|false
    {
        if ([] === self::$errors) {
            return false;
        }
        $out = self::$errors;
        self::$errors = [];

        return $out;
    }

    public static function lastError(): string|false
    {
        if (null === self::$lastError) {
            return false;
        }

        return self::$lastError;
    }

    public static function open(
        string $mailbox,
        string $user,
        string $password,
        int $flags,
        int $retries,
        Context $ctx
    ): Variable|false {
        unset($user, $password, $flags, $retries); // auth unused for local mbox v1
        self::clearErrors();

        if (str_starts_with($mailbox, '{')) {
            return self::openRemoteOrFail($mailbox, $ctx);
        }

        return self::openLocalMbox($mailbox, $ctx);
    }

    private static function openLocalMbox(string $path, Context $ctx): Variable|false
    {
        if (!is_file($path) || !is_readable($path)) {
            $msg = "Couldn't open stream {$path}";
            self::pushError($msg);
            self::warnImap('imap_open(): '.$msg);

            return false;
        }

        try {
            $messages = ImapMboxEngine::parseFile($path);
        } catch (\Throwable $e) {
            $msg = "Couldn't open stream {$path}";
            self::pushError($msg);
            self::warnImap('imap_open(): '.$msg);

            return false;
        }

        return self::wrapConnection($ctx, $path, $messages);
    }

    private static function openRemoteOrFail(string $mailbox, Context $ctx): Variable|false
    {
        // Support in-process fixture: {php-compiler-mbox}/absolute/path
        if (preg_match('#^\{php-compiler-mbox\}(.+)$#i', $mailbox, $m)) {
            return self::openLocalMbox($m[1], $ctx);
        }

        $host = '127.0.0.1';
        $port = 143;
        if (preg_match('/^\{([^:}]+)(?::(\d+))?/', $mailbox, $m)) {
            $host = $m[1];
            if (isset($m[2]) && '' !== $m[2]) {
                $port = (int) $m[2];
            }
        }

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (false !== $sock) {
            fclose($sock);
            // TCP up but no IMAP client yet — treat as open failure (no c-client).
            $msg = "Couldn't open stream {$mailbox}";
            self::pushError('No IMAP protocol client available for remote mailbox (v1 local-mbox only)');
            self::pushError($msg);
            self::warnImap('imap_open(): '.$msg);

            return false;
        }

        $msg = "Couldn't open stream {$mailbox}";
        self::pushError($msg);
        self::warnImap('imap_open(): '.$msg);

        return false;
    }

    /**
     * @param list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}> $messages
     */
    private static function wrapConnection(Context $ctx, string $mailbox, array $messages): Variable
    {
        VmImapConnection::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[VmImapConnection::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'mailbox' => $mailbox,
            'closed' => false,
            'messages' => $messages,
        ];
        $var->object($object);

        return $var;
    }

    public static function close(ObjectEntry $object, int $flags = 0): bool
    {
        unset($flags);
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::$state[$object->id]['closed'] = true;
        unset(self::$state[$object->id]);

        return true;
    }

    public static function numMsg(ObjectEntry $object): int|false
    {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }

        return \count($st['messages']);
    }

    /**
     * @return list<int>|false
     */
    public static function search(ObjectEntry $object, string $criteria): array|false
    {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }

        return ImapMboxEngine::search($st['messages'], $criteria);
    }

    public static function fetchBody(
        ObjectEntry $object,
        int $msgNo,
        string $section,
        int $flags = 0
    ): string|false {
        unset($flags);
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $idx = $msgNo - 1;
        if ($idx < 0 || $idx >= \count($st['messages'])) {
            return false;
        }
        $msg = $st['messages'][$idx];
        // Section "" or "1" → body text for single-part mbox messages.
        if ('' === $section || '1' === $section || 'TEXT' === strtoupper($section)) {
            return $msg['body'];
        }

        return $msg['body'];
    }

    public static function headerInfo(
        ObjectEntry $object,
        int $msgNo,
        int $fromLength,
        int $subjectLength,
        Context $ctx
    ): Variable|false {
        unset($fromLength, $subjectLength);
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $idx = $msgNo - 1;
        if ($idx < 0 || $idx >= \count($st['messages'])) {
            return false;
        }
        $msg = $st['messages'][$idx];
        $map = $msg['headerMap'];

        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
        $obj = new ObjectEntry($ctx->classes['stdclass']);
        $obj->constructed = true;

        $set = static function (string $name, string|int $value) use ($obj): void {
            $prop = $obj->allocateProperty($name);
            if (\is_int($value)) {
                $prop->int($value);
            } else {
                $prop->string($value);
            }
        };

        $set('subject', (string) ($map['subject'] ?? ''));
        $set('from', (string) ($map['from'] ?? ''));
        $set('to', (string) ($map['to'] ?? ''));
        $set('date', (string) ($map['date'] ?? ''));
        $set('message_id', (string) ($map['message-id'] ?? ''));
        $set('Size', \strlen($msg['raw']));
        $set('Msgno', $msgNo);
        $set('udate', 0);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($obj);

        return $var;
    }

    public static function isConnectionObject(?ObjectEntry $object): bool
    {
        return null !== $object && VmImapConnection::CLASS_LC === strtolower($object->class->name);
    }

    public static function isLiveConnectionObject(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    /**
     * @return array{mailbox: string, closed: bool, messages: list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>}|null
     */
    private static function liveState(ObjectEntry $object): ?array
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return null;
        }

        return self::$state[$object->id];
    }

    private static function pushError(string $message): void
    {
        self::$errors[] = $message;
        self::$lastError = $message;
    }

    private static function warnImap(string $message): void
    {
        $vm = VM::running();
        if (null === $vm) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame = $vm->builtinHandlerFrame();
        if (null === $frame) {
            $frames = $vm->context->runStackFrames();
            $frame = [] !== $frames ? $frames[0] : null;
        }
        $vm->context->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $vm->context,
            $frame
        );
    }

    /**
     * @param list<int>|list<string> $rows
     */
    public static function intListToHashTable(array $rows): HashTable
    {
        $ht = new HashTable();
        foreach ($rows as $row) {
            $slot = new Variable();
            if (\is_int($row)) {
                $slot->int($row);
            } else {
                $slot->string((string) $row);
            }
            $ht->append($slot);
        }

        return $ht;
    }

    /**
     * @param list<string> $rows
     */
    public static function stringListToHashTable(array $rows): HashTable
    {
        $ht = new HashTable();
        foreach ($rows as $row) {
            $slot = new Variable();
            $slot->string($row);
            $ht->append($slot);
        }

        return $ht;
    }
}
