<?php
/**
 * #36382 — `-1 === fseek($this->prop, $offset)` must seek to $offset, not to -1.
 *
 * php-cfg emits UnaryMinus for the compare literal next to the call; the fseek
 * offset hoist (#16523) must not steal it when the real offset is a named local.
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(fseek) / streams.c php_stream_seek.
 */
class S
{
    public $stream;

    public function __construct()
    {
        $this->stream = fopen('php://memory', 'r+');
        fwrite($this->stream, 'hello');
    }

    public function seek($o): void
    {
        if (-1 === fseek($this->stream, $o)) {
            echo "FAIL\n";

            return;
        }
        echo "SEEKOK\n";
    }
}

(new S())->seek(0);
