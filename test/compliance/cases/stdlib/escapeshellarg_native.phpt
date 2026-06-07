--TEST--
stdlib escapeshellarg() native VM quoting without host delegation (#4861, ext/standard/exec.c)
--FILE--
<?php
declare(strict_types=1);
echo escapeshellarg("it's a test"), "\n";
echo escapeshellarg(''), "\n";
echo escapeshellarg('plain'), "\n";
try {
    escapeshellarg([]);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
'it'\''s a test'
''
'plain'
TypeError
