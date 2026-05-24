--TEST--
function with : string return type returns literal (issue #205)
--FILE--
<?php
function g(): string {
    return 'ok';
}
echo g();
--EXPECT--
ok
