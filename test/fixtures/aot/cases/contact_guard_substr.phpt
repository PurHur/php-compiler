--TEST--
AOT: contact guard substr compare on $_REQUEST name (#697)
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY=name=PostDev
--FILE--
<?php
$name = $_REQUEST['name'] ?? '';
if ($name == '') {
    echo 'empty';
    exit(0);
}
if ($name != substr($name, 0, 200)) {
    echo 'substr';
    exit(0);
}
echo 'ok';
--EXPECT--
ok
--EXPECT_EXIT--
0
