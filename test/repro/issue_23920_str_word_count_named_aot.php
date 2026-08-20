<?php
/**
 * AOT probe #23920 — named str_word_count(string:/format:/characters:) must compile.
 * ReflectionFunction may be unavailable under thin AOT.
 */
try {
    $named = str_word_count(string: 'a-b', format: 0, characters: '-');
    echo 1 === $named ? "named_ok\n" : "named_bad=$named\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter')
        ? "named_rejected\n"
        : ('named_other='.get_class($e)."\n");
}
$pos = str_word_count('a-b', 0, '-');
echo 1 === $pos ? "pos_ok\n" : "pos_bad=$pos\n";
