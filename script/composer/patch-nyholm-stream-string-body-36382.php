<?php

declare(strict_types=1);

/**
 * Rewrite Nyholm Stream::create string bodies to avoid php://memory fopen (#36382).
 *
 * Under IncludeHelper thin AOT, fopen('php://memory') either fails is_resource()
 * (JitStreamIoKernel handle vs Zend resource — #23777) or NestedJIT StreamIo hangs
 * the compile. Slim $response->getBody()->write('hello') never returns without this.
 *
 * Writes sibling AotStringStream36382.php so AutoloadDiscovery / PSR-4 can resolve it.
 *
 * php-src reference: ext/standard/streams.c php_stream_memory_ops — string buffers are
 * a valid in-memory stream shape; Zend still uses php://memory, we use an explicit
 * StreamInterface for the Composer fixture only.
 */
$stream = $argv[1] ?? '';
if ('' === $stream || !is_file($stream)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-stream-string-body-36382.php <Stream.php>\n");
    exit(1);
}
$t = file_get_contents($stream);
if (false === $t) {
    fwrite(STDERR, "cannot read {$stream}\n");
    exit(1);
}

$dir = dirname($stream);
$helperPath = $dir.'/AotStringStream36382.php';
$helper = <<<'PHP'
<?php

declare(strict_types=1);

namespace Nyholm\Psr7;

use Psr\Http\Message\StreamInterface;

/**
 * In-memory StreamInterface for Composer AOT fixtures (#36382).
 * Avoids php://memory until thin-AOT is_resource sees kernel stream handles (#23777).
 */
final class AotStringStream36382 implements StreamInterface
{
    /** @var string */
    private $buffer;

    /** @var int */
    private $pos = 0;

    /** @var bool */
    private $open = true;

    public function __construct(string $buffer = '')
    {
        $this->buffer = $buffer;
    }

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function close(): void
    {
        $this->open = false;
        $this->buffer = '';
        $this->pos = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return \strlen($this->buffer);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= \strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        $len = \strlen($this->buffer);
        if (\SEEK_SET === $whence) {
            $this->pos = (int) $offset;
        } elseif (\SEEK_CUR === $whence) {
            $this->pos += (int) $offset;
        } elseif (\SEEK_END === $whence) {
            $this->pos = $len + (int) $offset;
        }
        if ($this->pos < 0) {
            $this->pos = 0;
        }
        if ($this->pos > $len) {
            $this->pos = $len;
        }
    }

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function isWritable(): bool
    {
        return $this->open;
    }

    public function write($string): int
    {
        if (!$this->open) {
            throw new \RuntimeException('Stream is closed');
        }
        $string = (string) $string;
        $n = \strlen($string);
        $before = \substr($this->buffer, 0, $this->pos);
        $after = \substr($this->buffer, $this->pos);
        $this->buffer = $before . $string . $after;
        $this->pos += $n;

        return $n;
    }

    public function isReadable(): bool
    {
        return $this->open;
    }

    public function read($length): string
    {
        if (!$this->open) {
            throw new \RuntimeException('Stream is closed');
        }
        $length = (int) $length;
        if ($length <= 0) {
            return '';
        }
        $chunk = \substr($this->buffer, $this->pos, $length);
        $this->pos += \strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        if (!$this->open) {
            throw new \RuntimeException('Stream is closed');
        }
        $chunk = \substr($this->buffer, $this->pos);
        $this->pos = \strlen($this->buffer);

        return $chunk;
    }

    public function getMetadata($key = null)
    {
        if (null === $key) {
            return [];
        }

        return null;
    }
}
PHP;

if (!is_file($helperPath) || !str_contains((string) file_get_contents($helperPath), 'class AotStringStream36382')) {
    if (false === file_put_contents($helperPath, $helper)) {
        fwrite(STDERR, "cannot write {$helperPath}\n");
        exit(1);
    }
    fwrite(STDOUT, "wrote {$helperPath} (#36382)\n");
}

if (str_contains($t, 'AOT (#36382): string body without php://memory fopen')) {
    fwrite(STDOUT, "Stream.php string-body already patched (#36382)\n");
    exit(0);
}

$old = <<<'PHP'
        if (\is_string($body)) {
            if (200000 <= \strlen($body)) {
                $body = self::openZvalStream($body);
            } else {
                $resource = \fopen('php://memory', 'r+');
                \fwrite($resource, $body);
                \fseek($resource, 0);
                $body = $resource;
            }
        }

        if (!\is_resource($body)) {
            throw new \InvalidArgumentException('First argument to Stream::create() must be a string, resource or StreamInterface');
        }

        return new self($body);
    }
PHP;

$new = <<<'PHP'
        if (\is_string($body)) {
            // AOT (#36382): string body without php://memory fopen — IncludeHelper thin AOT
            // is_resource() does not treat JitStreamIoKernel handles as live (#23777).
            return new AotStringStream36382($body);
        }

        if (!\is_resource($body)) {
            throw new \InvalidArgumentException('First argument to Stream::create() must be a string, resource or StreamInterface');
        }

        return new self($body);
    }
PHP;

if (!str_contains($t, $old)) {
    fwrite(STDERR, "Stream::create string-body pattern not found in {$stream}\n");
    exit(1);
}
$t = str_replace($old, $new, $t);
// Drop any prior in-file helper class if a previous patch left one.
if (str_contains($t, 'final class AotStringStream36382 implements StreamInterface')) {
    $pos = strpos($t, "\n/**\n * In-memory StreamInterface for Composer AOT fixtures");
    if (false === $pos) {
        $pos = strpos($t, "\nfinal class AotStringStream36382");
    }
    if (false !== $pos) {
        $t = substr($t, 0, $pos)."\n";
    }
}

if (false === file_put_contents($stream, $t)) {
    fwrite(STDERR, "cannot write {$stream}\n");
    exit(1);
}
fwrite(STDOUT, "patched Stream.php string-body for AOT (#36382)\n");
