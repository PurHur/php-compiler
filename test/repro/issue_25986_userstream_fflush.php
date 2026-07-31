<?php
// #25986 — fflush() on stream_wrapper_register() handles via stream_flush
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25986Wrap
{
    public $context;
    public $data = '';
    public $flushed = 0;

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_write($data)
    {
        $this->data .= $data;

        return strlen($data);
    }

    public function stream_flush()
    {
        $this->flushed++;

        return true;
    }

    public function stream_eof()
    {
        return true;
    }

    public function stream_stat()
    {
        return [];
    }
}

class Issue25986WrapFail
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_write($data)
    {
        return strlen($data);
    }

    public function stream_flush()
    {
        return false;
    }

    public function stream_eof()
    {
        return true;
    }

    public function stream_stat()
    {
        return [];
    }
}

class Issue25986WrapAbsent
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_write($data)
    {
        return strlen($data);
    }

    public function stream_eof()
    {
        return true;
    }

    public function stream_stat()
    {
        return [];
    }
}

@stream_wrapper_unregister('i25986');
stream_wrapper_register('i25986', Issue25986Wrap::class);
$h = fopen('i25986://x', 'w');
fwrite($h, 'hi');
echo 'flush=', var_export(fflush($h), true), "\n";
fclose($h);
stream_wrapper_unregister('i25986');

@stream_wrapper_unregister('i25986f');
stream_wrapper_register('i25986f', Issue25986WrapFail::class);
$h2 = fopen('i25986f://x', 'w');
fwrite($h2, 'hi');
echo 'flush_false=', var_export(fflush($h2), true), "\n";
fclose($h2);
stream_wrapper_unregister('i25986f');

@stream_wrapper_unregister('i25986a');
stream_wrapper_register('i25986a', Issue25986WrapAbsent::class);
$h3 = fopen('i25986a://x', 'w');
fwrite($h3, 'hi');
echo 'flush_absent=', var_export(fflush($h3), true), "\n";
fclose($h3);
stream_wrapper_unregister('i25986a');
