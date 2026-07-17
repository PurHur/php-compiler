--TEST--
Language: BackedEnum::from()/tryFrom() null coerces like Zend (#20072, zend_enum.c)
--FILE--
<?php
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
foreach ([
    fn() => E::from(null),
    fn() => I::from(null),
    fn() => I::tryFrom(null),
    fn() => E::tryFrom(null),
] as $fn) {
    try {
        var_export($fn());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
ValueError: "0" is not a valid backing value for enum E
ValueError: 0 is not a valid backing value for enum I
NULL
NULL
