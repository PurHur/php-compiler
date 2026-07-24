--TEST--
stdlib filter_var() FILTER_VALIDATE_EMAIL domain labels (issue #22826, ext/filter/logical_filters.c)
--FILE--
<?php
foreach ([
    'test@-example.com',
    'a@b..com',
    'test@.com',
    'test@example.com.',
    'ok@example.com',
    'a@b',
    'user@ex--ample.com',
    'a@b-.com',
    'a@-b.com',
    'user@1example.com',
    'a@b.1com',
] as $e) {
    echo $e, '=', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
--EXPECT--
test@-example.com=false
a@b..com=false
test@.com=false
test@example.com.=false
ok@example.com='ok@example.com'
a@b=false
user@ex--ample.com='user@ex--ample.com'
a@b-.com=false
a@-b.com=false
user@1example.com='user@1example.com'
a@b.1com=false
