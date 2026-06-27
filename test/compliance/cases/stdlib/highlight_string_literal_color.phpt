--TEST--
stdlib highlight_string() string literals use Zend #DD0000 color (#12401)
--FILE--
<?php
$html = highlight_string('<?php $x = "x"; ?>', true);
if (!is_string($html) || !str_contains($html, '#DD0000')) {
    echo "fail\n";
    exit(1);
}
echo "ok\n";
--EXPECT--
ok
--EXPECT_EXIT--
0
