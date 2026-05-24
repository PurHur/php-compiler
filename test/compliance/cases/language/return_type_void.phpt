--TEST--
function with : void return type allows bare return (issue #205)
--FILE--
<?php
function h(): void {
    return;
}
h();
echo 'done';
--EXPECT--
done
