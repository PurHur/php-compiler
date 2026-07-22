--TEST--
stdlib XSLTProcessor::setProfiling path/null (#22272, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom') || !class_exists('XSLTProcessor', false)) {
    echo 'skip';
}
?>
--FILE--
<?php
$p = new XSLTProcessor();
echo 'has=', method_exists($p, 'setProfiling') ? '1' : '0', "\n";
$path = sys_get_temp_dir() . '/phpc-xslt-profile-22272.txt';
@unlink($path);
$r1 = $p->setProfiling($path);
echo 'path=', $r1 ? '1' : '0', "\n";
$r2 = $p->setProfiling('');
echo 'empty=', $r2 ? '1' : '0', "\n";
$r3 = $p->setProfiling(null);
echo 'null=', $r3 ? '1' : '0', "\n";
try {
    $p->setProfiling([]);
    echo "array=ok\n";
} catch (TypeError $e) {
    echo 'array=', (str_contains($e->getMessage(), '?string') && str_contains($e->getMessage(), 'array')) ? 'type' : 'other', "\n";
}
try {
    $p->setProfiling("a\0b");
    echo "nul=ok\n";
} catch (ValueError $e) {
    echo 'nul=', str_contains($e->getMessage(), 'null bytes') ? 'value' : 'other', "\n";
}
?>
--EXPECT--
has=1
path=1
empty=1
null=1
array=type
nul=value
