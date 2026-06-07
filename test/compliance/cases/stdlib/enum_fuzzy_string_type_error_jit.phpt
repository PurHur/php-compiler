--TEST--
stdlib similar_text()/str_word_count()/metaphone()/soundex() JIT — enum case TypeError (#5740)
--FILE--
<?php
enum E: string { case A = 'hello'; }
$p = 0;
foreach (['similar_text', 'str_word_count', 'metaphone', 'soundex'] as $fn) {
    try {
        if ('similar_text' === $fn) {
            similar_text(E::A, 'hello', $p);
        } elseif ('str_word_count' === $fn) {
            str_word_count(E::A);
        } elseif ('metaphone' === $fn) {
            metaphone(E::A);
        } else {
            soundex(E::A);
        }
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
similar_text: similar_text(): Argument #1 ($string1) must be of type string, E given
str_word_count: str_word_count(): Argument #1 ($string) must be of type string, E given
metaphone: metaphone(): Argument #1 ($string) must be of type string, E given
soundex: soundex(): Argument #1 ($string) must be of type string, E given
