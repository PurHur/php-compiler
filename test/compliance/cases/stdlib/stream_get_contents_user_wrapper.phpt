--TEST--
stdlib stream_get_contents() on user stream wrappers (#25970)
--FILE--
<?php
class SgcUserWrap {
    public int $pos = 0;
    public string $data = 'ABCDEFGH';
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
    public function stream_tell(): int {
        return $this->pos;
    }
    public function stream_seek(int $offset, int $whence): bool {
        if ($whence === SEEK_SET) {
            $this->pos = $offset;
        } elseif ($whence === SEEK_CUR) {
            $this->pos += $offset;
        } elseif ($whence === SEEK_END) {
            $this->pos = strlen($this->data) + $offset;
        } else {
            return false;
        }
        return $this->pos >= 0;
    }
}
@stream_wrapper_unregister('sgcuser');
stream_wrapper_register('sgcuser', SgcUserWrap::class);
$h = fopen('sgcuser://x', 'r');
echo stream_get_contents($h), "\n";
fclose($h);
$h = fopen('sgcuser://x', 'r');
echo fread($h, 2), "\n";
echo stream_get_contents($h), "\n";
fclose($h);
$h = fopen('sgcuser://x', 'r');
echo stream_get_contents($h, 3), "\n";
fclose($h);
$h = fopen('sgcuser://x', 'r');
echo stream_get_contents($h, -1, 2), "\n";
fclose($h);
stream_wrapper_unregister('sgcuser');
--EXPECT--
ABCDEFGH
AB
CDEFGH
ABC
CDEFGH
