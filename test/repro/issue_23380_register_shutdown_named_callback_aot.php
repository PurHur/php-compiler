<?php
/**
 * Issue #23380 AOT compile probe — named callback: (no Reflection).
 * php-src: ext/standard/basic_functions.stub.php
 *
 * Note: AOT shutdown drain currently segfaults for positional and named alike
 * (same as test/fixtures/aot/cases/register_shutdown_headers_sent.phpt on master).
 * This file is for compile/link verification of named-arg lowering.
 */
register_shutdown_function(callback: static function (): void {
    echo "bye\n";
});
echo "main\n";
?>
