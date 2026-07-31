--TEST--
stdlib opendir/readdir/scandir on user wrappers (userspace.c dir_*, #26002)
--FILE--
<?php
class DirOpsWrap {
    private $entries = ['.', '..', 'a', 'b'];
    private $i = 0;
    public function dir_opendir($path, $options) {
        echo "op:$path:$options\n";
        $this->i = 0;
        return true;
    }
    public function dir_readdir() {
        if ($this->i >= count($this->entries)) {
            return false;
        }
        $entry = $this->entries[$this->i];
        $this->i = $this->i + 1;
        return $entry;
    }
    public function dir_rewinddir() {
        echo "rewind\n";
        $this->i = 0;
        return true;
    }
    public function dir_closedir() {
        echo "close\n";
    }
}
stream_wrapper_register('dirops', DirOpsWrap::class);
$d = opendir('dirops://root');
var_export($d !== false);
echo "\n";
$out = [];
while (($e = readdir($d)) !== false) {
    $out[] = $e;
}
rewinddir($d);
$out2 = [];
while (($e = readdir($d)) !== false) {
    $out2[] = $e;
}
closedir($d);
echo implode(',', $out), "\n";
echo implode(',', $out2), "\n";
var_export(scandir('dirops://root'));
echo "\n";
var_export(scandir('dirops://root', SCANDIR_SORT_DESCENDING));
echo "\n";
stream_wrapper_unregister('dirops');
--EXPECT--
op:dirops://root:0
true
rewind
close
.,..,a,b
.,..,a,b
op:dirops://root:0
close
array (
  0 => '.',
  1 => '..',
  2 => 'a',
  3 => 'b',
)
op:dirops://root:0
close
array (
  0 => 'b',
  1 => 'a',
  2 => '..',
  3 => '.',
)
