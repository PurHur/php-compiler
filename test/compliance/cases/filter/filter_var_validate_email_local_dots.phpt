--TEST--
stdlib filter_var() FILTER_VALIDATE_EMAIL local-part dots (#29014, re-#22826)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'user.@example.com',
    'user..name@example.com',
    '.user@example.com',
    'ok@example.com',
    'user.name@example.com',
    'a@b..com',
] as $e) {
    echo $e, '=', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
--EXPECT--
user.@example.com=false
user..name@example.com=false
.user@example.com=false
ok@example.com='ok@example.com'
user.name@example.com='user.name@example.com'
a@b..com=false
