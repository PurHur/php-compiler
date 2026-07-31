<?php
// #25973 — file_exists()/filesize() via url_stat on user wrappers
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25973Wrap
{
    public $context;
    public $data = 'ABCDEFGH';

    public function stream_open($path, $mode, $options, &$opened_path = null)
    {
        return true;
    }

    public function stream_read($count)
    {
        return '';
    }

    public function stream_eof()
    {
        return true;
    }

    public function url_stat($path, $flags)
    {
        return ['size' => strlen($this->data)];
    }
}

@stream_wrapper_unregister('i25973');
stream_wrapper_register('i25973', Issue25973Wrap::class);

echo 'exists=', var_export(file_exists('i25973://x'), true), "\n";
echo 'filesize=', var_export(@filesize('i25973://x'), true), "\n";
echo 'is_file=', var_export(@is_file('i25973://x'), true), "\n";
stream_wrapper_unregister('i25973');
