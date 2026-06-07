--TEST--
Language: #[\AllowDynamicProperties] on method compile-time fatal (#6869)
--FILE--
<?php
class C {
    #[\AllowDynamicProperties]
    public function f(): void {}
}
echo "compiled\n";
--EXPECT_EXIT--
255
