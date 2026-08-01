--TEST--
Language: readonly class may use method-only trait (#26592)
--FILE--
<?php
trait T {
    public function f(): string {
        return 'ok';
    }
}
readonly class R {
    use T;
}
echo (new R())->f(), "\n";
--EXPECT--
ok
