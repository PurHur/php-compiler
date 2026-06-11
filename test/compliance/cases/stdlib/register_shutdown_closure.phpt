--TEST--
stdlib register_shutdown_function() runs closure callbacks at script end (#4852)
--FILE--
<?php
register_shutdown_function(static function (): void {
    echo "shutdown\n";
});
echo "main\n";
--EXPECT--
main
shutdown
--EXPECT_EXIT--
0
