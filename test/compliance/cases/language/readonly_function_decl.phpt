--TEST--
Language: readonly function declaration compiles and runs (#7428)
--FILE--
<?php
readonly function f(): void {
    echo "ok\n";
}
f();
--EXPECT--
ok
