<?php
// #25987 — unlink/mkdir/rmdir/rename on stream_wrapper_register() protocols
error_reporting(E_ALL);

class Issue25987Wrap
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_stat()
    {
        return [];
    }

    public function unlink($path)
    {
        echo 'call:unlink:'.$path, "\n";

        return true;
    }

    public function rename($from, $to)
    {
        echo 'call:rename:'.$from.'->'.$to, "\n";

        return true;
    }

    public function mkdir($path, $mode, $options)
    {
        echo 'call:mkdir:'.$path.':'.$mode.':'.$options, "\n";

        return true;
    }

    public function rmdir($path, $options)
    {
        echo 'call:rmdir:'.$path.':'.$options, "\n";

        return true;
    }
}

stream_wrapper_register('i25987', Issue25987Wrap::class);

echo 'unlink=', var_export(unlink('i25987://x'), true), "\n";
echo 'mkdir=', var_export(mkdir('i25987://d', 0755), true), "\n";
echo 'mkdir_r=', var_export(mkdir('i25987://d2', 0700, true), true), "\n";
echo 'rmdir=', var_export(rmdir('i25987://d'), true), "\n";
echo 'rename=', var_export(rename('i25987://a', 'i25987://b'), true), "\n";
stream_wrapper_unregister('i25987');
