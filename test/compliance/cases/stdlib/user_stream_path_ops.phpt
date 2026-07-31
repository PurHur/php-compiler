--TEST--
stdlib unlink/mkdir/rmdir/rename on user wrappers (userspace.c, #25987)
--FILE--
<?php
class PathOpsWrap {
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_stat() {
        return [];
    }
    public function unlink($path) {
        echo "call:unlink\n";
        return true;
    }
    public function rename($from, $to) {
        echo "call:rename\n";
        return true;
    }
    public function mkdir($path, $mode, $options) {
        echo "call:mkdir:$mode:$options\n";
        return true;
    }
    public function rmdir($path, $options) {
        echo "call:rmdir:$options\n";
        return true;
    }
}
stream_wrapper_register('pathops', PathOpsWrap::class);
var_export(unlink('pathops://x'));
echo "\n";
var_export(mkdir('pathops://d', 0755));
echo "\n";
var_export(mkdir('pathops://d2', 0700, true));
echo "\n";
var_export(rmdir('pathops://d'));
echo "\n";
var_export(rename('pathops://a', 'pathops://b'));
echo "\n";
stream_wrapper_unregister('pathops');
--EXPECT--
call:unlink
true
call:mkdir:493:8
true
call:mkdir:448:9
true
call:rmdir:8
true
call:rename
true
