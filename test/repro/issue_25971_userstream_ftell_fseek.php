<?php
// #25971 — ftell()/fseek()/rewind() on stream_wrapper_register() handles
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25971Wrap
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
        } elseif (SEEK_CUR === $whence) {
            $this->pos += $offset;
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

@stream_wrapper_unregister('i25971');
stream_wrapper_register('i25971', Issue25971Wrap::class);

$h = fopen('i25971://x', 'r');
echo 'fread2=', var_export(fread($h, 2), true), "\n";
echo 'tell=', var_export(ftell($h), true), "\n";
echo 'fseek3=', var_export(fseek($h, 3), true), "\n";
echo 'tell3=', var_export(ftell($h), true), "\n";
echo 'rewind=', var_export(rewind($h), true), "\n";
echo 'tell0=', var_export(ftell($h), true), "\n";
echo 'fseek_end=', var_export(fseek($h, -2, SEEK_END), true), "\n";
echo 'tell_end=', var_export(ftell($h), true), "\n";
fclose($h);
stream_wrapper_unregister('i25971');
