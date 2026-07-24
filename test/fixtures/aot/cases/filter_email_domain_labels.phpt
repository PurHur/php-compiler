--TEST--
AOT: filter_var() FILTER_VALIDATE_EMAIL domain labels (issue #22826)
--FILE--
<?php
foreach ([
    'test@-example.com',
    'a@b..com',
    'ok@example.com',
    'test@example.com.',
] as $e) {
    echo $e, '=', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
--EXPECT--
test@-example.com=false
a@b..com=false
ok@example.com='ok@example.com'
test@example.com.=false
--EXPECT_EXIT--
0
