--TEST--
stdlib finfo_open/finfo_file/finfo_buffer MIME (#3366)
--FILE--
<?php
declare(strict_types=1);

echo defined('FILEINFO_MIME_TYPE') ? "const_ok\n" : "const_bad\n";
echo extension_loaded('fileinfo') ? "ext_ok\n" : "ext_bad\n";
echo function_exists('finfo_open') ? "fn_ok\n" : "fn_bad\n";
echo class_exists('finfo', false) ? "class_ok\n" : "class_bad\n";

$tmp = tempnam(sys_get_temp_dir(), 'finfo');
file_put_contents($tmp, "<?php echo 1;\n");

$f = finfo_open(FILEINFO_MIME_TYPE);
echo ($f instanceof finfo) ? "open_ok\n" : "open_bad\n";
echo finfo_file($f, $tmp), "\n";
echo finfo_buffer($f, '<?php echo 1;'), "\n";

$bad = @finfo_file($f, $tmp . '-missing');
echo false === $bad ? "missing_false\n" : "missing_not_false\n";

finfo_set_flags($f, FILEINFO_MIME);
$mime = finfo_buffer($f, 'ASCII only');
echo (str_starts_with($mime, 'text/plain; charset=') ? 'mime_flags_ok' : 'mime_flags_bad'), "\n";

finfo_close($f);

$obj = new finfo(FILEINFO_MIME_TYPE);
echo $obj->file($tmp), "\n";
echo $obj->buffer('<?php'), "\n";

@unlink($tmp);
--EXPECT--
const_ok
ext_ok
fn_ok
class_ok
open_ok
text/x-php
text/x-php
missing_false
mime_flags_ok
text/x-php
text/x-php
