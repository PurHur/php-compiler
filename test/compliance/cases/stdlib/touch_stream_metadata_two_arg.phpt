--TEST--
stdlib touch two-arg stream_metadata value [mtime,mtime] (#26288, php-src filestat.c)
--FILE--
<?php
class W {
    public $context;
    public static $calls = [];
    public function stream_metadata($path, $option, $value) {
        self::$calls[] = [$option, $value];
        return true;
    }
    public function url_stat($path, $flags) {
        return [
            'size'=>0,'mode'=>0100644,'uid'=>0,'gid'=>0,
            'atime'=>0,'mtime'=>0,'ctime'=>0,'nlink'=>1,
            'rdev'=>0,'ino'=>0,'dev'=>0,'blksize'=>-1,'blocks'=>-1,
        ];
    }
}
stream_wrapper_register('pw', 'W');

W::$calls = [];
touch('pw://x', 100);
var_export(W::$calls);
echo "\n";

W::$calls = [];
touch('pw://x', 100, 200);
var_export(W::$calls);
echo "\n";

W::$calls = [];
touch('pw://x');
var_export(W::$calls);
echo "\n";

W::$calls = [];
chmod('pw://x', 0600);
var_export(W::$calls);
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 1,
    1 => array (
      0 => 100,
      1 => 100,
    ),
  ),
)
array (
  0 => array (
    0 => 1,
    1 => array (
      0 => 100,
      1 => 200,
    ),
  ),
)
array (
  0 => array (
    0 => 1,
    1 => array (
    ),
  ),
)
array (
  0 => array (
    0 => 6,
    1 => 384,
  ),
)
