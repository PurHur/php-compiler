--TEST--
Language: readonly function declaration rejected — php-src parse error (#10012, was #7428)
--FILE--
<?php
readonly function f(): void {
    echo "ok\n";
}
f();
--EXPECT_EXIT--
255
