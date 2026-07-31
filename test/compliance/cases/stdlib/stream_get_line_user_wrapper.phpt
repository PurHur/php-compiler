--TEST--
stdlib stream_get_line() on user stream wrappers via stream_read (#26003)
--FILE--
<?php
class StreamGetLineUserWrap {
    public int $pos = 0;
    public string $data = "a|b|c";
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool {
        return true;
    }
    public function stream_read(int $count) {
        if ($this->pos >= strlen($this->data)) {
            return false;
        }
        $r = substr($this->data, $this->pos, $count);
        $this->pos += strlen($r);
        return $r;
    }
    public function stream_eof(): bool {
        return $this->pos >= strlen($this->data);
    }
    public function stream_stat(): array {
        return [];
    }
}
@stream_wrapper_unregister('uwline');
stream_wrapper_register('uwline', StreamGetLineUserWrap::class);
$h = fopen('uwline://x', 'r');
var_export(stream_get_line($h, 100, '|')); echo "\n";
var_export(stream_get_line($h, 100, '|')); echo "\n";
var_export(stream_get_line($h, 100, '|')); echo "\n";
var_export(stream_get_line($h, 100, '|')); echo "\n";
fclose($h);
stream_wrapper_unregister('uwline');
--EXPECT--
'a'
'b'
'c'
false
