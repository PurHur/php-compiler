--TEST--
Language: __call/__callStatic typed return without strict_types coerces int→string (#26427)
--FILE--
<?php
class C {
    public function __call(string $n, array $a): string {
        return 5;
    }
    public static function __callStatic(string $n, array $a): string {
        return 7;
    }
}
echo (new C)->foo(), PHP_EOL;
echo C::bar(), PHP_EOL;
--EXPECT--
5
7
