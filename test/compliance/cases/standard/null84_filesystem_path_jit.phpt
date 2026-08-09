--TEST--
stdlib filesystem path batch — null soft-null DEP+false/ValueError on 8.4 JIT (#21245)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$null = null;
foreach (
    [
        'unlink' => static fn () => @unlink($null),
        'mkdir' => static fn () => @mkdir($null),
        'touch' => static fn () => @touch($null),
        'rename' => static fn () => @rename($null, 'x'),
        'copy' => static fn () => @copy($null, 'x'),
        'chmod' => static fn () => @chmod($null, 0644),
        'rmdir' => static fn () => @rmdir($null),
        'is_writable' => static fn () => @is_writable($null),
        'readfile' => static fn () => @readfile($null),
        'filesize' => static fn () => @filesize($null),
        'opendir' => static fn () => @opendir($null),
    ] as $label => $call
) {
    try {
        $r = $call();
        echo $label, ':', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label, ':TypeError:', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $label, ':ValueError:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
unlink:false
mkdir:false
touch:false
rename:false
copy:ValueError:Path must not be empty
chmod:false
rmdir:false
is_writable:false
readfile:ValueError:Path must not be empty
filesize:false
opendir:false
