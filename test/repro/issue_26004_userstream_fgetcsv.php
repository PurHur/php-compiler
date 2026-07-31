<?php
// #26004 — fgetcsv() on stream_wrapper_register() handles via stream_read + VmCsv
error_reporting(E_ALL & ~E_WARNING);

class Issue26004CsvWrap
{
    public $context;
    public int $pos = 0;
    public string $data = "a,b\n1,2\n";

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

stream_wrapper_register('i26004', Issue26004CsvWrap::class);
$h = fopen('i26004://x', 'r');
echo 'row1=', var_export(fgetcsv($h), true), "\n";
echo 'row2=', var_export(fgetcsv($h), true), "\n";
echo 'row3=', var_export(fgetcsv($h), true), "\n";
fclose($h);
stream_wrapper_unregister('i26004');
