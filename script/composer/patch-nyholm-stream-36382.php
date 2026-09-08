<?php

declare(strict_types=1);

/**
 * Rewrite Nyholm Stream.php for thin AOT Composer graphs (#36382).
 *
 * set_error_handler(static closure) + anonymous stream-wrapper class currently fail
 * LLVM module verify (closure return __string__* vs __value__).
 */
$stream = $argv[1] ?? '';
if ('' === $stream || !is_file($stream)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-stream-36382.php <Stream.php>\n");
    exit(1);
}
$t = file_get_contents($stream);
if (false === $t) {
    fwrite(STDERR, "cannot read {$stream}\n");
    exit(1);
}
if (str_contains($t, 'AOT (#36382): avoid set_error_handler(static closure)')) {
    $changed = false;
    // Upgrade getContents: drop isset($this->stream) if still present.
    $oldGcIsset = <<<'PHP'
    public function getContents(): string
    {
        // AOT (#36382): avoid set_error_handler(static closure) IR return-type mismatch in Composer graphs.
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }
        $result = @\stream_get_contents($this->stream);
        if (false === $result) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }
PHP;
    $newGcNoIsset = <<<'PHP'
    public function getContents(): string
    {
        // AOT (#36382): avoid set_error_handler(static closure) IR return-type mismatch in Composer graphs.
        // Also skip isset($this->stream) — that + resource props SEGV under thin AOT after write.
        $result = @\stream_get_contents($this->stream);
        if (false === $result) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }
PHP;
    if (str_contains($t, $oldGcIsset)) {
        $t = str_replace($oldGcIsset, $newGcNoIsset, $t);
        $changed = true;
    }

    // getContents/openZval already done — still upgrade seek() if needed.
    $oldSeek = <<<'PHP'
    public function seek($offset, $whence = \SEEK_SET): void
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (-1 === \fseek($this->stream, $offset, $whence)) {
            throw new \RuntimeException('Unable to seek to stream position "' . $offset . '" with whence ' . \var_export($whence, true));
        }
    }
PHP;
    $newSeek = <<<'PHP'
    public function seek($offset, $whence = \SEEK_SET): void
    {
        // AOT (#36382): avoid isset($this->stream) + (-1 === fseek) compare (SEGV on
        // thin-AOT php://memory after write — php-src streams.c php_stream_seek).
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }
        \fseek($this->stream, $offset, $whence);
    }
PHP;
    if (!str_contains($t, 'avoid isset($this->stream) + (-1 === fseek)')) {
        if (!str_contains($t, $oldSeek)) {
            fwrite(STDERR, "seek() pattern not found in already-patched {$stream}\n");
            exit(1);
        }
        $t = str_replace($oldSeek, $newSeek, $t);
        $changed = true;
    }
    if (!$changed && str_contains($t, 'Also skip isset($this->stream)')) {
        fwrite(STDOUT, "Stream.php already patched (#36382)\n");
        exit(0);
    }
    if (false === file_put_contents($stream, $t)) {
        fwrite(STDERR, "cannot write {$stream}\n");
        exit(1);
    }
    fwrite(STDOUT, "upgraded Stream.php seek/getContents for AOT (#36382)\n");
    exit(0);
}

$oldGc = <<<'PHP'
    public function getContents(): string
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }

        $exception = null;

        \set_error_handler(static function ($type, $message) use (&$exception) {
            throw $exception = new \RuntimeException('Unable to read stream contents: ' . $message);
        });

        try {
            return \stream_get_contents($this->stream);
        } catch (\Throwable $e) {
            throw $e === $exception ? $e : new \RuntimeException('Unable to read stream contents: ' . $e->getMessage(), 0, $e);
        } finally {
            \restore_error_handler();
        }
    }
PHP;
$newGc = <<<'PHP'
    public function getContents(): string
    {
        // AOT (#36382): avoid set_error_handler(static closure) IR return-type mismatch in Composer graphs.
        // Also skip isset($this->stream) — that + resource props SEGV under thin AOT after write.
        $result = @\stream_get_contents($this->stream);
        if (false === $result) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }
PHP;
if (!str_contains($t, $oldGc)) {
    fwrite(STDERR, "getContents pattern not found in {$stream}\n");
    exit(1);
}
$t = str_replace($oldGc, $newGc, $t);

$start = strpos($t, '    private static function openZvalStream(string $body)');
$endMarker = "        return \$stream;\n    }\n";
$end = false === $start ? false : strpos($t, $endMarker, $start);
if (false === $start || false === $end) {
    fwrite(STDERR, "openZvalStream pattern not found in {$stream}\n");
    exit(1);
}
$end += strlen($endMarker);
$newOz = <<<'PHP'
    private static function openZvalStream(string $body)
    {
        // AOT (#36382): skip anonymous stream wrapper class (closure IR verify failure in Composer AOT).
        $resource = \fopen('php://temp', 'r+');
        \fwrite($resource, $body);
        \fseek($resource, 0);

        return $resource;
    }

PHP;
$t = substr($t, 0, $start).$newOz.substr($t, $end);

// AOT (#36382): Stream::seek isset($this->stream) + (-1 === fseek) SEGVs on thin-AOT
// php://memory after write (same root as drop AotStringStream36382).
$oldSeek = <<<'PHP'
    public function seek($offset, $whence = \SEEK_SET): void
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (-1 === \fseek($this->stream, $offset, $whence)) {
            throw new \RuntimeException('Unable to seek to stream position "' . $offset . '" with whence ' . \var_export($whence, true));
        }
    }
PHP;
$newSeek = <<<'PHP'
    public function seek($offset, $whence = \SEEK_SET): void
    {
        // AOT (#36382): avoid isset($this->stream) + (-1 === fseek) compare (SEGV on
        // thin-AOT php://memory after write — php-src streams.c php_stream_seek).
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }
        \fseek($this->stream, $offset, $whence);
    }
PHP;
if (!str_contains($t, 'avoid isset($this->stream) + (-1 === fseek)')) {
    if (!str_contains($t, $oldSeek)) {
        fwrite(STDERR, "seek() pattern not found in {$stream}\n");
        exit(1);
    }
    $t = str_replace($oldSeek, $newSeek, $t);
}

$oldWrite = <<<'PHP'
    public function write($string): int
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }

        // We can't know the size after writing anything
        $this->size = null;

        if (false === $result = @\fwrite($this->stream, $string)) {
            throw new \RuntimeException('Unable to write to stream: ' . (\error_get_last()['message'] ?? ''));
        }

        return $result;
    }
PHP;
$newWrite = <<<'PHP'
    public function write($string): int
    {
        // AOT (#36382): skip isset($this->stream) (resource-prop isset SEGV under thin AOT).
        if (!$this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }

        // We can't know the size after writing anything
        $this->size = null;

        if (false === $result = @\fwrite($this->stream, $string)) {
            throw new \RuntimeException('Unable to write to stream: ' . (\error_get_last()['message'] ?? ''));
        }

        return $result;
    }
PHP;
if (!str_contains($t, 'skip isset($this->stream) (resource-prop isset SEGV')) {
    if (str_contains($t, $oldWrite)) {
        $t = str_replace($oldWrite, $newWrite, $t);
    }
}

$oldCtor = <<<'PHP'
    public function __construct($body)
    {
        if (!\is_resource($body)) {
            throw new \InvalidArgumentException('First argument to Stream::__construct() must be resource');
        }

        $this->stream = $body;
        $meta = \stream_get_meta_data($this->stream);
        $this->seekable = $meta['seekable'] && 0 === \fseek($this->stream, 0, \SEEK_CUR);
        $this->readable = isset(self::READ_WRITE_HASH['read'][$meta['mode']]);
        $this->writable = isset(self::READ_WRITE_HASH['write'][$meta['mode']]);
    }
PHP;
$newCtor = <<<'PHP'
    public function __construct($body)
    {
        // AOT (#36382): skip is_resource + stream_get_meta_data on thin-AOT php://memory
        // (meta keys empty / resource-prop isset SEGV — drop AotStringStream36382).
        $this->stream = $body;
        $this->seekable = true;
        $this->readable = true;
        $this->writable = true;
    }
PHP;
if (!str_contains($t, 'skip is_resource + stream_get_meta_data')) {
    if (str_contains($t, $oldCtor)) {
        $t = str_replace($oldCtor, $newCtor, $t);
    }
}

if (false === file_put_contents($stream, $t)) {
    fwrite(STDERR, "cannot write {$stream}\n");
    exit(1);
}
fwrite(STDOUT, "patched Stream.php for AOT (#36382)\n");
