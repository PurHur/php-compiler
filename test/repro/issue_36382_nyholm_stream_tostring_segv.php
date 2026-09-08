<?php
/**
 * #36382 — Nyholm-like Stream::__toString after php://memory write under AOT.
 *
 * Avoid Stream::seek()'s isset($this->stream)+(-1===fseek) and skip meta_data in
 * the minimal repro (fixture patches Stream/StreamTrait for Slim).
 *
 * php-src: ext/standard/streams.c php_stream_seek / php_stream_memory_ops.
 */
final class NyholmLikeStream36382
{
    /** @var resource */
    private $stream;

    public function __construct($body)
    {
        $this->stream = $body;
    }

    public static function create(string $body = ''): self
    {
        $resource = \fopen('php://memory', 'r+');
        \fwrite($resource, $body);
        \fseek($resource, 0);

        return new self($resource);
    }

    public function write($string): int
    {
        $n = \fwrite($this->stream, (string) $string);
        if (false === $n) {
            throw new \RuntimeException('Unable to write');
        }

        return $n;
    }

    public function getContents(): string
    {
        $result = \stream_get_contents($this->stream);
        if (false === $result) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }

    public function __toString(): string
    {
        \rewind($this->stream);

        return $this->getContents();
    }
}

$s = NyholmLikeStream36382::create('');
$s->write('hello');
echo (string) $s, "\n";
