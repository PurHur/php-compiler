<?php
// #25970 — stream_get_contents() on stream_wrapper_register() handles
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo "W:$s\n";

    return true;
});

class Issue25970Wrap
{
    public $context;
    public int $pos = 0;
    public string $data = 'ABCDEFGH';

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

    public function stream_tell(): int
    {
        return $this->pos;
    }

    public function stream_seek(int $offset, int $whence): bool
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

    public function stream_stat(): array
    {
        return [];
    }
}

@stream_wrapper_unregister('i25970');
stream_wrapper_register('i25970', Issue25970Wrap::class);

$h = fopen('i25970://x', 'r');
echo 'sgc_full=', var_export(stream_get_contents($h), true), "\n";
fclose($h);

$h = fopen('i25970://x', 'r');
echo 'fread2=', var_export(fread($h, 2), true), "\n";
echo 'sgc_rest=', var_export(stream_get_contents($h), true), "\n";
fclose($h);

$h = fopen('i25970://x', 'r');
echo 'sgc_maxlen=', var_export(stream_get_contents($h, 3), true), "\n";
fclose($h);

$h = fopen('i25970://x', 'r');
echo 'sgc_offset=', var_export(stream_get_contents($h, -1, 2), true), "\n";
fclose($h);

stream_wrapper_unregister('i25970');
