<?php
// #25972 — fwrite() on stream_wrapper_register() handles
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25972Wrap
{
    public $context;
    public $pos = 0;
    public $data = 'ABCDEFGH';

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_read($count)
    {
        $r = substr($this->data, $this->pos, $count);
        $this->pos += strlen($r);

        return $r;
    }

    public function stream_write($data)
    {
        $this->data .= $data;
        $this->pos = strlen($this->data);

        return strlen($data);
    }

    public function stream_eof()
    {
        return $this->pos >= strlen($this->data);
    }

    public function stream_tell()
    {
        return $this->pos;
    }

    public function stream_seek($offset, $whence)
    {
        if (SEEK_SET === $whence) {
            $this->pos = $offset;
        } elseif (SEEK_END === $whence) {
            $this->pos = strlen($this->data) + $offset;
        } else {
            return false;
        }

        return $this->pos >= 0;
    }

    public function stream_stat()
    {
        return ['size' => strlen($this->data)];
    }
}

@stream_wrapper_unregister('i25972');
stream_wrapper_register('i25972', Issue25972Wrap::class);

$h = fopen('i25972://x', 'r+');
echo 'fwrite=', var_export(fwrite($h, 'ZZ'), true), "\n";
echo 'tell=', var_export(ftell($h), true), "\n";
rewind($h);
echo 'all=', var_export(stream_get_contents($h), true), "\n";
fclose($h);
stream_wrapper_unregister('i25972');
