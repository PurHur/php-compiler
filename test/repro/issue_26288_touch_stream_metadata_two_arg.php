<?php
/**
 * #26288 — two-arg touch on userspace wrapper passes [mtime, mtime] (php-src filestat.c).
 */
class W
{
    public $context;
    /** @var list<array{0:int,1:mixed}> */
    public static $calls = [];

    public function stream_metadata($path, $option, $value)
    {
        self::$calls[] = [$option, $value];

        return true;
    }

    public function url_stat($path, $flags)
    {
        return [
            'size' => 0,
            'mode' => 0100644,
            'uid' => 0,
            'gid' => 0,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'nlink' => 1,
            'rdev' => 0,
            'ino' => 0,
            'dev' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}

stream_wrapper_register('pw', 'W');

W::$calls = [];
touch('pw://x', 100);
echo 'two_arg=';
var_export(W::$calls);
echo "\n";

W::$calls = [];
touch('pw://x', 100, 200);
echo 'three_arg=';
var_export(W::$calls);
echo "\n";

W::$calls = [];
touch('pw://x');
echo 'zero_arg=';
var_export(W::$calls);
echo "\n";

W::$calls = [];
chmod('pw://x', 0600);
echo 'chmod=';
var_export(W::$calls);
echo "\n";
