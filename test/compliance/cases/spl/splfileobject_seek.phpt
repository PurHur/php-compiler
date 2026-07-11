--TEST--
SPL SplFileObject seek/fseek/getCurrentLine (#14227, ext/spl/spl_file_object.c)
--FILE--
<?php
file_put_contents(sys_get_temp_dir().'/phpc_spl_seek.txt', "line0\nline1\nline2\nline3\nline4\n");
$path = sys_get_temp_dir().'/phpc_spl_seek.txt';
echo method_exists(SplFileObject::class, 'seek') ? "seek_yes\n" : "seek_no\n";
echo method_exists(SplFileObject::class, 'fseek') ? "fseek_yes\n" : "fseek_no\n";
echo method_exists(SplFileObject::class, 'getCurrentLine') ? "gcl_yes\n" : "gcl_no\n";
$fo = new SplFileObject($path);
$fo->seek(3);
echo $fo->key(), "\n";
echo str_starts_with((string) $fo->current(), 'line3') ? "line3_ok\n" : "line3_fail\n";
echo 0 === $fo->fseek(0) ? "fseek_ok\n" : "fseek_fail\n";
echo str_starts_with((string) $fo->getCurrentLine(), 'line0') ? "gcl_ok\n" : "gcl_fail\n";
@unlink($path);
?>
--EXPECT--
seek_yes
fseek_yes
gcl_yes
3
line3_ok
fseek_ok
gcl_ok
