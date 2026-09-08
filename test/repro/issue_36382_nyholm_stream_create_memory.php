<?php
/**
 * #36382 — Nyholm Stream::create($string) shape under thin AOT.
 *
 * After #37259, fopen('php://memory') + is_resource() works; Slim no longer needs
 * AotStringStream36382. Mirrors Nyholm\Psr7\Stream::create string arm + write + __toString.
 *
 * php-src: ext/standard/streams.c php_stream_memory_ops / php://memory.
 */
final class MiniNyholmStream36382
{
    /** @var resource */
    private $stream;

    public static function create($body = '')
    {
        if (\is_string($body)) {
            $resource = \fopen('php://memory', 'r+');
            \fwrite($resource, $body);
            \fseek($resource, 0);
            $body = $resource;
        }

        if (!\is_resource($body)) {
            throw new \InvalidArgumentException('First argument must be a string or resource');
        }

        $self = new self();
        $self->stream = $body;

        return $self;
    }

    public function write($string): int
    {
        $n = \fwrite($this->stream, (string) $string);
        if (false === $n) {
            throw new \RuntimeException('Unable to write');
        }

        return $n;
    }

    public function __toString(): string
    {
        \rewind($this->stream);
        $data = \stream_get_contents($this->stream);
        if (false === $data) {
            return '';
        }

        return $data;
    }
}

$s = MiniNyholmStream36382::create('');
$s->write('hello');
echo (string) $s, "\n";
