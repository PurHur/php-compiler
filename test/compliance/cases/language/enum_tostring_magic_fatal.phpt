--TEST--
Language: enum cannot define __toString — compile-time fatal (#5055)
--FILE--
<?php
enum E implements Stringable {
    case A;
    public function __toString(): string {
        return 'a';
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
