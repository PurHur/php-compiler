--TEST--
stdlib str_word_count/strrev/chunk_split/similar_text/levenshtein/substr_count JIT — enum case TypeError (#8797)
--FILE--
<?php
enum E: int { case A = 1; }
$p = 0;
$tests = [
    ['str_word_count', static fn () => str_word_count(E::A)],
    ['strrev', static fn () => strrev(E::A)],
    ['chunk_split', static fn () => chunk_split(E::A, 1)],
    ['similar_text', static fn () => similar_text(E::A, 'x', $p)],
    ['levenshtein', static fn () => levenshtein(E::A, 'x')],
    ['substr_count', static fn () => substr_count(E::A, '1')],
];
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
str_word_count: str_word_count(): Argument #1 ($string) must be of type string, E given
strrev: strrev(): Argument #1 ($string) must be of type string, E given
chunk_split: chunk_split(): Argument #1 ($string) must be of type string, E given
similar_text: similar_text(): Argument #1 ($string1) must be of type string, E given
levenshtein: levenshtein(): Argument #1 ($string1) must be of type string, E given
substr_count: substr_count(): Argument #1 ($haystack) must be of type string, E given
