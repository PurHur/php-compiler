--TEST--
Language: interface method with body — compile fatal (#14890)
--FILE--
<?php
interface I {
    public function f(): void {
        echo "unreachable\n";
    }
}
echo "unreachable\n";
--EXPECT_EXIT--
255
