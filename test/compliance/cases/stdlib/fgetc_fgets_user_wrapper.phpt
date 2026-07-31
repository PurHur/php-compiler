--TEST--
stdlib fgetc()/fgets() on user stream wrappers via stream_read (#25985)
--FILE--
<?php
class FgetcUserWrap {
    public int $pos = 0;
    public string $data = 'ab';
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool {
        return true;
    }
    public function stream_read(int $count): string {
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
@stream_wrapper_unregister('fgcuser');
stream_wrapper_register('fgcuser', FgetcUserWrap::class);
$h = fopen('fgcuser://x', 'r');
var_export(fgetc($h)); echo "\n";
var_export(fgetc($h)); echo "\n";
var_export(fgetc($h)); echo "\n";
fclose($h);
stream_wrapper_unregister('fgcuser');

class FgetsUserWrap {
    public int $pos = 0;
    public string $data = "line1\nline2\n";
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path = null): bool {
        return true;
    }
    public function stream_read(int $count): string {
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
stream_wrapper_register('fgcuser', FgetsUserWrap::class);
$h = fopen('fgcuser://x', 'r');
var_export(fgets($h)); echo "\n";
var_export(fgets($h)); echo "\n";
var_export(fgets($h)); echo "\n";
fclose($h);
$h = fopen('fgcuser://x', 'r');
var_export(fgets($h, 3)); echo "\n";
var_export(fgets($h)); echo "\n";
fclose($h);
stream_wrapper_unregister('fgcuser');
--EXPECT--
'a'
'b'
false
'line1
'
'line2
'
false
'li'
'ne1
'
