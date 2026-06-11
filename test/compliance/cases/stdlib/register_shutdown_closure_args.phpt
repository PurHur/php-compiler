--TEST--
stdlib register_shutdown_function() passes user args to closure at drain (#4852)
--FILE--
<?php
register_shutdown_function(static function (string $msg): void {
    echo $msg, "\n";
}, 'bye');
echo "main\n";
--EXPECT--
main
bye
--EXPECT_EXIT--
0
