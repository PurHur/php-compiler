--TEST--
language: __toString in echo context (issue #146)
--FILE--
<?php
class M {
    function __toString(): string {
        return 'M';
    }
}
echo new M, "\n";
--EXPECT--
M
