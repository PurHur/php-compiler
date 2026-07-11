--TEST--
stdlib var_export() __set_state empty array — compact array( header (#14272, ext/standard/var.c)
--FILE--
<?php
class VE
{
    public static function __set_state(array $a): self
    {
        return new self();
    }
}

echo var_export(VE::__set_state([]), true);
echo "\n";
--EXPECT--
\VE::__set_state(array(
))
