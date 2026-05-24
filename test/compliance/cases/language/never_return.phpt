--TEST--
never return type: exit ends script without returning (issue #1358)
--FILE--
<?php
function stop(): never {
    exit('gone');
}
stop();
echo 'after';
--EXPECT--
gone
