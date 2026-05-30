--TEST--
language: function return type bool (#55)
--FILE--
<?php
function flag(): bool {
    return true;
}
echo flag() ? 'yes' : 'no';
--EXPECT--
yes
