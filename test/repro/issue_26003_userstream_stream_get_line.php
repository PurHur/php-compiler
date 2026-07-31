<?php
// #26003 — stream_get_line() on stream_wrapper_register() handles via stream_read
error_reporting(E_ALL & ~E_WARNING);

class Issue26003LineWrap
{
    public $context;
    public int $pos = 0;
    public string $data = 'a|b|c';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool
    {
        return true;
    }

    public function stream_read(int $count)
    {
        if ($this->pos >= strlen($this->data)) {
            return false;
        }
        $r = substr($this->data, $this->pos, $count);
        $this->pos += strlen($r);

        return $r;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

stream_wrapper_register('i26003', Issue26003LineWrap::class);
$h = fopen('i26003://x', 'r');
echo '1=', var_export(stream_get_line($h, 100, '|'), true), "\n";
echo '2=', var_export(stream_get_line($h, 100, '|'), true), "\n";
echo '3=', var_export(stream_get_line($h, 100, '|'), true), "\n";
echo '4=', var_export(stream_get_line($h, 100, '|'), true), "\n";
fclose($h);
stream_wrapper_unregister('i26003');
