--TEST--
stdlib fgetcsv() on user stream wrappers via stream_read + VmCsv (#26004)
--FILE--
<?php
class FgetcsvUserWrap {
    public int $pos = 0;
    public string $data = "a,b\n1,2\n";
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
@stream_wrapper_unregister('fcsvuser');
stream_wrapper_register('fcsvuser', FgetcsvUserWrap::class);
$h = fopen('fcsvuser://x', 'r');
var_export(fgetcsv($h)); echo "\n";
var_export(fgetcsv($h)); echo "\n";
var_export(fgetcsv($h)); echo "\n";
fclose($h);
stream_wrapper_unregister('fcsvuser');
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => '1',
  1 => '2',
)
false
