<?php
/**
 * #36382 — Nyholm Stream::seek shape: isset($this->stream) + (-1 === fseek) after write.
 *
 * After #37349 the UnaryMinus fseek-offset hoist no longer steals the compare -1,
 * so this must stay green under thin AOT (fixture patches keep stock isset).
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(fseek) / streams.c php_stream_seek.
 */
final class NyholmSeekIssetShape36382
{
    /** @var resource|null */
    private $stream;
    private bool $seekable = true;

    public function __construct($body)
    {
        $this->stream = $body;
    }

    public static function create(string $body = ''): self
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $body);
        fseek($resource, 0);

        return new self($resource);
    }

    public function write(string $string): int
    {
        if (!isset($this->stream)) {
            throw new RuntimeException('Stream is detached');
        }
        $n = fwrite($this->stream, $string);

        return false === $n ? 0 : $n;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!isset($this->stream)) {
            throw new RuntimeException('Stream is detached');
        }
        if (!$this->seekable) {
            throw new RuntimeException('Stream is not seekable');
        }
        if (-1 === fseek($this->stream, $offset, $whence)) {
            throw new RuntimeException('Unable to seek');
        }
    }

    public function getContents(): string
    {
        if (!isset($this->stream)) {
            throw new RuntimeException('Stream is detached');
        }
        $result = stream_get_contents($this->stream);
        if (false === $result) {
            throw new RuntimeException('Unable to read');
        }

        return $result;
    }

    public function __toString(): string
    {
        if ($this->seekable) {
            $this->seek(0);
        }

        return $this->getContents();
    }
}

$s = NyholmSeekIssetShape36382::create('');
$s->write('hello');
echo (string) $s, "\n";
