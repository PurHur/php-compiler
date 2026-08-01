--TEST--
Language: __invoke typed return weak mode coerces int→string (#26426)
--FILE--
<?php
class C {
    public function __invoke(): string {
        return 5;
    }
}
try {
    echo (new C)(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
5
