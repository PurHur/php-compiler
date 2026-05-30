--TEST--
language: nullable function return type (#55)
--FILE--
<?php
function maybe(): ?string {
    return null;
}
echo maybe() ?? 'null';
--EXPECT--
null
