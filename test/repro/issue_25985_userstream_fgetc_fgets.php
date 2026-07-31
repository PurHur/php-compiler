<?php
// #25985 — fgetc()/fgets() on stream_wrapper_register() handles via stream_read
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25985DataWrap
{
    public $context;
    public int $pos = 0;
    public string $data = 'ab';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
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

@stream_wrapper_unregister('i25985');
stream_wrapper_register('i25985', Issue25985DataWrap::class);
$h = fopen('i25985://x', 'r');
echo 'c1=', var_export(fgetc($h), true), "\n";
echo 'c2=', var_export(fgetc($h), true), "\n";
echo 'c3=', var_export(fgetc($h), true), "\n";
fclose($h);
stream_wrapper_unregister('i25985');

class Issue25985LinesWrap
{
    public $context;
    public int $pos = 0;
    public string $data = "line1\nline2\n";

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
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

stream_wrapper_register('i25985', Issue25985LinesWrap::class);
$h = fopen('i25985://x', 'r');
echo 'l1=', var_export(fgets($h), true), "\n";
echo 'l2=', var_export(fgets($h), true), "\n";
echo 'l3=', var_export(fgets($h), true), "\n";
fclose($h);

$h = fopen('i25985://x', 'r');
echo 'len=', var_export(fgets($h, 3), true), "\n";
echo 'rest=', var_export(fgets($h), true), "\n";
fclose($h);

stream_wrapper_unregister('i25985');
