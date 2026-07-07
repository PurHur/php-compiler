--TEST--
stdlib register_shutdown_function() passes enum case object to inline closure (#5751)
--FILE--
<?php
enum E: int
{
    case A = 1;
}

register_shutdown_function(
    function (E $e): void {
        var_dump($e);
    },
    E::A
);
echo "ok\n";
--EXPECT--
ok
enum(E::A)
--EXPECT_EXIT--
0
