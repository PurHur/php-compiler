--TEST--
stdlib debug_backtrace() limit: named parameter (#10485)
--FILE--
<?php
declare(strict_types=1);
function backtrace_limit_probe(): void
{
    echo count(debug_backtrace(limit: 1)), "\n";
}
backtrace_limit_probe();
?>
--EXPECT--
1
