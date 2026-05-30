--TEST--
AOT: register_shutdown_function() + headers_sent() (issue #3120)
--FILE--
<?php
function shutdown_cb(): void {
    echo "shutdown\n";
}
register_shutdown_function('shutdown_cb');
echo headers_sent() ? "sent\n" : "not\n";
--EXPECT--
not
shutdown
--EXPECT_EXIT--
0
