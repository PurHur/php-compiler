--TEST--
Stdlib: http_build_query() backed enum → scalar under PROFILE=8.4 (#23703, ext/standard/http.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E: string { case A = 'a'; }
enum I: int { case One = 1; }
enum U { case B; }
echo http_build_query(['e' => E::A]), "\n";
echo http_build_query(['i' => I::One]), "\n";
echo http_build_query(['n' => ['e' => E::A]]), "\n";
try {
    echo http_build_query(['u' => U::B]), "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
try {
    echo http_build_query(E::A), "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
--EXPECT--
e=a
i=1
n%5Be%5D=a
ValueError: Unbacked enum U cannot be converted to a string
TypeError: http_build_query(): Argument #1 ($data) must not be an enum, E given
