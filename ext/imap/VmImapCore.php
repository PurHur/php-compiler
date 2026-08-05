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

    /** c-client LATT_* bits used by imap_getmailboxes() (php_imap.h). */
    public const LATT_NOINFERIORS = 0x01;

    public const LATT_HASNOCHILDREN = 0x40;

    /**
     * @var array<int, array{
     *     mailbox: string,
     *     closed: bool,
     *     messages: list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>,
     *     subscribed: array<string, true>
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
            'subscribed' => [],
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

    /**
     * imap_list() — local directory scan matching c-client * / % patterns (#27799).
     *
     * @return list<string>|false
     */
    public static function listMailboxes(ObjectEntry $object, string $reference, string $pattern): array|false
    {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $names = self::scanLocalMailboxes($st['mailbox'], $reference, $pattern, 'imap_list');
        if (null === $names) {
            return false;
        }
        if ([] === $names) {
            return false;
        }

        return $names;
    }

    /**
     * imap_lsub() — subscribed subset of {@see listMailboxes()} (#27799).
     *
     * @return list<string>|false
     */
    public static function listSubscribed(ObjectEntry $object, string $reference, string $pattern): array|false
    {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $names = self::scanLocalMailboxes($st['mailbox'], $reference, $pattern, 'imap_lsub');
        if (null === $names) {
            return false;
        }
        $out = [];
        foreach ($names as $name) {
            if (isset($st['subscribed'][$name])) {
                $out[] = $name;
            }
        }
        if ([] === $out) {
            return false;
        }

        return $out;
    }

    /**
     * imap_getmailboxes() — list + stdClass{name,delimiter,attributes} (#27799).
     */
    public static function getMailboxes(
        ObjectEntry $object,
        string $reference,
        string $pattern,
        Context $ctx
    ): Variable|false {
        $names = self::listMailboxes($object, $reference, $pattern);
        if (false === $names) {
            return false;
        }
        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
        $ht = new HashTable();
        foreach ($names as $name) {
            $obj = new ObjectEntry($ctx->classes['stdclass']);
            $obj->constructed = true;
            $nameProp = $obj->allocateProperty('name');
            $nameProp->string($name);
            $delimProp = $obj->allocateProperty('delimiter');
            $delimProp->string('/');
            $attrProp = $obj->allocateProperty('attributes');
            $attrProp->int(self::LATT_NOINFERIORS | self::LATT_HASNOCHILDREN);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return $var;
    }

    public static function subscribe(ObjectEntry $object, string $mailbox): bool
    {
        $st = self::liveStateMutable($object);
        if (null === $st) {
            return false;
        }
        $path = self::normalizeLocalMailboxPath($mailbox, $st['mailbox']);
        if (null === $path || !is_file($path)) {
            $msg = "Can't subscribe to {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_subscribe(): '.$msg);

            return false;
        }
        self::$state[$object->id]['subscribed'][$path] = true;

        return true;
    }

    public static function unsubscribe(ObjectEntry $object, string $mailbox): bool
    {
        $st = self::liveStateMutable($object);
        if (null === $st) {
            return false;
        }
        $path = self::normalizeLocalMailboxPath($mailbox, $st['mailbox']);
        if (null === $path) {
            $msg = "Can't unsubscribe from {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_unsubscribe(): '.$msg);

            return false;
        }
        if (!isset(self::$state[$object->id]['subscribed'][$path])) {
            $msg = "Can't unsubscribe from {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_unsubscribe(): '.$msg);

            return false;
        }
        unset(self::$state[$object->id]['subscribed'][$path]);

        return true;
    }

    public static function createMailbox(ObjectEntry $object, string $mailbox): bool
    {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $path = self::normalizeLocalMailboxPath($mailbox, $st['mailbox']);
        if (null === $path) {
            $msg = "Can't create mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_createmailbox(): '.$msg);

            return false;
        }
        if (is_file($path) || is_dir($path)) {
            $msg = "Can't create mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_createmailbox(): '.$msg);

            return false;
        }
        $dir = \dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            $msg = "Can't create mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_createmailbox(): '.$msg);

            return false;
        }
        if (false === @file_put_contents($path, '')) {
            $msg = "Can't create mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_createmailbox(): '.$msg);

            return false;
        }

        return true;
    }

    public static function deleteMailbox(ObjectEntry $object, string $mailbox): bool
    {
        $st = self::liveStateMutable($object);
        if (null === $st) {
            return false;
        }
        $path = self::normalizeLocalMailboxPath($mailbox, $st['mailbox']);
        if (null === $path || !is_file($path)) {
            $msg = "Can't delete mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_deletemailbox(): '.$msg);

            return false;
        }
        if ($path === realpath($st['mailbox']) || $path === $st['mailbox']) {
            $msg = "Can't delete mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_deletemailbox(): '.$msg);

            return false;
        }
        if (!@unlink($path)) {
            $msg = "Can't delete mailbox {$mailbox}";
            self::pushError($msg);
            self::warnImap('imap_deletemailbox(): '.$msg);

            return false;
        }
        unset(self::$state[$object->id]['subscribed'][$path]);

        return true;
    }

    public static function renameMailbox(ObjectEntry $object, string $from, string $to): bool
    {
        $st = self::liveStateMutable($object);
        if (null === $st) {
            return false;
        }
        $fromPath = self::normalizeLocalMailboxPath($from, $st['mailbox']);
        $toPath = self::normalizeLocalMailboxPath($to, $st['mailbox']);
        if (null === $fromPath || null === $toPath || !is_file($fromPath)) {
            $msg = "Can't rename mailbox {$from}";
            self::pushError($msg);
            self::warnImap('imap_renamemailbox(): '.$msg);

            return false;
        }
        if (is_file($toPath) || is_dir($toPath)) {
            $msg = "Can't rename mailbox {$from}";
            self::pushError($msg);
            self::warnImap('imap_renamemailbox(): '.$msg);

            return false;
        }
        $dir = \dirname($toPath);
        if (!is_dir($dir) || !is_writable($dir)) {
            $msg = "Can't rename mailbox {$from}";
            self::pushError($msg);
            self::warnImap('imap_renamemailbox(): '.$msg);

            return false;
        }
        if (!@rename($fromPath, $toPath)) {
            $msg = "Can't rename mailbox {$from}";
            self::pushError($msg);
            self::warnImap('imap_renamemailbox(): '.$msg);

            return false;
        }
        $toReal = realpath($toPath);
        if (false !== $toReal) {
            $toPath = $toReal;
        }
        if (isset(self::$state[$object->id]['subscribed'][$fromPath])) {
            unset(self::$state[$object->id]['subscribed'][$fromPath]);
            self::$state[$object->id]['subscribed'][$toPath] = true;
        }
        if ($fromPath === $st['mailbox'] || $fromPath === realpath($st['mailbox'])) {
            self::$state[$object->id]['mailbox'] = $toPath;
        }

        return true;
    }

    /**
     * @return list<string>|null null on hard failure (missing dir / remote)
     */
    private static function scanLocalMailboxes(
        string $openMailbox,
        string $reference,
        string $pattern,
        string $warnFn = 'imap_list'
    ): ?array {
        $dir = self::resolveListReference($reference, $openMailbox);
        if (null === $dir) {
            $msg = "Can't open mailbox {$reference}";
            self::pushError($msg);
            self::warnImap($warnFn.'(): '.$msg);

            return null;
        }
        if (!is_dir($dir) || !is_readable($dir)) {
            $msg = "Can't open mailbox {$reference}";
            self::pushError($msg);
            self::warnImap($warnFn.'(): '.$msg);

            return null;
        }
        $entries = @scandir($dir);
        if (false === $entries) {
            $msg = "Can't open mailbox {$reference}";
            self::pushError($msg);
            self::warnImap($warnFn.'(): '.$msg);

            return null;
        }
        $out = [];
        $dir = rtrim($dir, '/');
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (!is_file($full)) {
                continue;
            }
            $real = realpath($full);
            if (false !== $real) {
                $full = $real;
            }
            $base = basename($full);
            if (self::matchMailboxPattern($base, $pattern) || self::matchMailboxPattern($full, $pattern)) {
                $out[] = $full;
            }
        }
        sort($out);

        return $out;
    }

    private static function resolveListReference(string $reference, string $openMailbox): ?string
    {
        $reference = trim($reference);
        if (str_starts_with($reference, '{') && !preg_match('#^\{php-compiler-mbox\}#i', $reference)) {
            return null;
        }
        if (preg_match('#^\{php-compiler-mbox\}(.+)$#i', $reference, $m)) {
            $reference = $m[1];
        }
        if ('' === $reference) {
            $base = realpath($openMailbox);
            if (false === $base) {
                $base = $openMailbox;
            }

            return \dirname($base);
        }
        if (is_dir($reference)) {
            $real = realpath($reference);

            return false !== $real ? $real : $reference;
        }
        // Reference may be a mailbox path prefix — use its directory when it names a file.
        if (is_file($reference)) {
            $real = realpath($reference);

            return \dirname(false !== $real ? $real : $reference);
        }
        $asDir = rtrim($reference, '/');
        if (is_dir($asDir)) {
            $real = realpath($asDir);

            return false !== $real ? $real : $asDir;
        }

        return null;
    }

    private static function normalizeLocalMailboxPath(string $mailbox, string $openMailbox): ?string
    {
        $mailbox = trim($mailbox);
        if (str_starts_with($mailbox, '{') && !preg_match('#^\{php-compiler-mbox\}#i', $mailbox)) {
            return null;
        }
        if (preg_match('#^\{php-compiler-mbox\}(.+)$#i', $mailbox, $m)) {
            $mailbox = $m[1];
        }
        if ('' === $mailbox) {
            return null;
        }
        if ('/' !== $mailbox[0] && !preg_match('#^[A-Za-z]:[\\\\/]#', $mailbox)) {
            $base = realpath($openMailbox);
            if (false === $base) {
                $base = $openMailbox;
            }
            $mailbox = \dirname($base).'/'.$mailbox;
        }
        if (is_file($mailbox) || is_dir($mailbox)) {
            $real = realpath($mailbox);
            if (false !== $real) {
                return $real;
            }
        }

        return $mailbox;
    }

    private static function matchMailboxPattern(string $name, string $pattern): bool
    {
        if ('' === $pattern) {
            return false;
        }
        $regex = '';
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; ++$i) {
            $c = $pattern[$i];
            if ('*' === $c) {
                $regex .= '.*';
            } elseif ('%' === $c) {
                $regex .= '[^/]*';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }

        return 1 === preg_match('/^'.$regex.'$/D', $name);
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

    /**
     * imap_append() — append RFC822 message to a local mailbox file (#27814).
     */
    public static function append(
        ObjectEntry $object,
        string $folder,
        string $message,
        ?string $options = null,
        ?string $internalDate = null
    ): bool {
        unset($options);
        $st = self::liveStateMutable($object);
        if (null === $st) {
            return false;
        }
        $path = self::normalizeLocalMailboxPath($folder, $st['mailbox']);
        if (null === $path) {
            $msg = "Can't append to mailbox {$folder}";
            self::pushError($msg);
            self::warnImap('imap_append(): '.$msg);

            return false;
        }
        // Allow appending into a newly created empty mailbox file.
        if (!is_file($path)) {
            $dir = \dirname($path);
            if (!is_dir($dir) || !is_writable($dir)) {
                $msg = "Can't append to mailbox {$folder}";
                self::pushError($msg);
                self::warnImap('imap_append(): '.$msg);

                return false;
            }
            if (false === @file_put_contents($path, '')) {
                $msg = "Can't append to mailbox {$folder}";
                self::pushError($msg);
                self::warnImap('imap_append(): '.$msg);

                return false;
            }
        }
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = rtrim($message, "\n")."\n";
        $fromLine = self::buildFromEnvelopeLine($message, $internalDate);
        if (!str_starts_with($message, 'From ')) {
            $chunk = $fromLine.$message;
            if (!str_ends_with($chunk, "\n")) {
                $chunk .= "\n";
            }
        } else {
            $chunk = $message;
            if (!str_ends_with($chunk, "\n")) {
                $chunk .= "\n";
            }
        }
        // Separate messages with a blank line when file already has content.
        $existing = @file_get_contents($path);
        if (false === $existing) {
            $msg = "Can't append to mailbox {$folder}";
            self::pushError($msg);
            self::warnImap('imap_append(): '.$msg);

            return false;
        }
        $prefix = ('' !== $existing && !str_ends_with($existing, "\n")) ? "\n" : '';
        if (false === @file_put_contents($path, $prefix.$chunk, FILE_APPEND)) {
            $msg = "Can't append to mailbox {$folder}";
            self::pushError($msg);
            self::warnImap('imap_append(): '.$msg);

            return false;
        }

        $openPath = $st['mailbox'];
        $openReal = realpath($openPath);
        $pathReal = realpath($path);
        if ((false !== $openReal && $openReal === $pathReal) || $openPath === $path) {
            try {
                self::$state[$object->id]['messages'] = ImapMboxEngine::parseFile($path);
            } catch (\Throwable $e) {
                // Disk write succeeded; keep prior in-memory view on parse failure.
            }
        }

        return true;
    }

    /**
     * imap_savebody() — write message body/section to a file path (#27814).
     */
    public static function saveBody(
        ObjectEntry $object,
        string $file,
        int $msgNo,
        string $section = '',
        int $flags = 0
    ): bool {
        $body = self::fetchBody($object, $msgNo, $section, $flags);
        if (false === $body) {
            $msg = "Can't save body for message {$msgNo}";
            self::pushError($msg);
            self::warnImap('imap_savebody(): '.$msg);

            return false;
        }
        if (false === @file_put_contents($file, $body)) {
            $msg = "Can't save body for message {$msgNo}";
            self::pushError($msg);
            self::warnImap('imap_savebody(): '.$msg);

            return false;
        }

        return true;
    }

    /**
     * imap_fetchmime() — MIME headers for section (local single-part v1) (#27814).
     */
    public static function fetchMime(
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
        // Single-part mbox: section "" / "1" / "TEXT" → full header block.
        if ('' === $section || '1' === $section || 'TEXT' === strtoupper($section)) {
            return $msg['headers']."\n";
        }

        return $msg['headers']."\n";
    }

    /**
     * imap_bodystruct() — part structure object for local single-part messages (#27814).
     */
    public static function bodyStruct(
        ObjectEntry $object,
        int $msgNo,
        string $section,
        Context $ctx
    ): Variable|false {
        $st = self::liveState($object);
        if (null === $st) {
            return false;
        }
        $idx = $msgNo - 1;
        if ($idx < 0 || $idx >= \count($st['messages'])) {
            return false;
        }
        unset($section); // v1: single-part body only
        $msg = $st['messages'][$idx];
        $map = $msg['headerMap'];
        $ctype = (string) ($map['content-type'] ?? 'text/plain');
        $subtype = 'PLAIN';
        $type = 0; // TYPETEXT
        if (preg_match('#^([^/]+)/([^;]+)#', $ctype, $m)) {
            $major = strtolower(trim($m[1]));
            $subtype = strtoupper(trim($m[2]));
            if ('text' === $major) {
                $type = 0;
            } elseif ('multipart' === $major) {
                $type = 1;
            } elseif ('message' === $major) {
                $type = 2;
            } elseif ('application' === $major) {
                $type = 3;
            } elseif ('audio' === $major) {
                $type = 4;
            } elseif ('image' === $major) {
                $type = 5;
            } elseif ('video' === $major) {
                $type = 6;
            } else {
                $type = 7; // TYPEOTHER
            }
        }
        $encoding = 0; // ENC7BIT
        $cte = strtolower((string) ($map['content-transfer-encoding'] ?? ''));
        if (str_contains($cte, 'quoted-printable')) {
            $encoding = 4;
        } elseif (str_contains($cte, 'base64')) {
            $encoding = 3;
        } elseif (str_contains($cte, '8bit')) {
            $encoding = 1;
        } elseif (str_contains($cte, 'binary')) {
            $encoding = 2;
        }

        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
        $obj = new ObjectEntry($ctx->classes['stdclass']);
        $obj->constructed = true;
        $setInt = static function (string $name, int $value) use ($obj): void {
            $prop = $obj->allocateProperty($name);
            $prop->int($value);
        };
        $setStr = static function (string $name, string $value) use ($obj): void {
            $prop = $obj->allocateProperty($name);
            $prop->string($value);
        };
        $setBool = static function (string $name, bool $value) use ($obj): void {
            $prop = $obj->allocateProperty($name);
            $prop->bool($value);
        };

        $body = $msg['body'];
        $bytes = \strlen($body);
        $lines = 0 === $bytes ? 0 : substr_count($body, "\n") + (str_ends_with($body, "\n") ? 0 : 1);

        $setInt('type', $type);
        $setInt('encoding', $encoding);
        $setBool('ifsubtype', true);
        $setStr('subtype', $subtype);
        $setBool('ifdescription', false);
        $setStr('description', '');
        $setBool('ifid', isset($map['content-id']));
        $setStr('id', (string) ($map['content-id'] ?? ''));
        $setInt('lines', $lines);
        $setInt('bytes', $bytes);
        $setBool('ifdisposition', isset($map['content-disposition']));
        $setStr('disposition', (string) ($map['content-disposition'] ?? ''));
        $setBool('ifdparameters', false);
        $setBool('ifparameters', false);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($obj);

        return $var;
    }

    private static function buildFromEnvelopeLine(string $message, ?string $internalDate): string
    {
        $sender = 'unknown@php-compiler.local';
        if (preg_match('/^From:\s*(.+)$/mi', $message, $m)) {
            $from = trim($m[1]);
            if (preg_match('/<([^>]+)>/', $from, $em)) {
                $sender = $em[1];
            } elseif (preg_match('/\S+@\S+/', $from, $em)) {
                $sender = $em[0];
            }
        }
        if (null !== $internalDate && '' !== trim($internalDate)) {
            $date = trim($internalDate);
        } else {
            $date = gmdate('D M d H:i:s Y');
        }

        return 'From '.$sender.' '.$date."\n";
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
     * @return array{
     *     mailbox: string,
     *     closed: bool,
     *     messages: list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>,
     *     subscribed: array<string, true>
     * }|null
     */
    private static function liveState(ObjectEntry $object): ?array
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return null;
        }

        return self::$state[$object->id];
    }

    /**
     * @return array{
     *     mailbox: string,
     *     closed: bool,
     *     messages: list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>,
     *     subscribed: array<string, true>
     * }|null
     */
    private static function liveStateMutable(ObjectEntry $object): ?array
    {
        return self::liveState($object);
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
