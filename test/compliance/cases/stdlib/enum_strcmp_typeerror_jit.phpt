--TEST--
stdlib strcmp()/strncmp()/strcasecmp()/strncasecmp()/levenshtein() JIT — enum case TypeError (#7132)
--FILE--
<?php
enum E: string { case A = 'x'; }
foreach (['strcmp', 'strncmp', 'strcasecmp', 'strncasecmp', 'levenshtein'] as $fn) {
    try {
        if ('strncmp' === $fn || 'strncasecmp' === $fn) {
            $fn(E::A, 'x', 1);
        } else {
            $fn(E::A, 'x');
        }
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
strcmp: strcmp(): Argument #1 ($string1) must be of type string, E given
strncmp: strncmp(): Argument #1 ($string1) must be of type string, E given
strcasecmp: strcasecmp(): Argument #1 ($string1) must be of type string, E given
strncasecmp: strncasecmp(): Argument #1 ($string1) must be of type string, E given
levenshtein: levenshtein(): Argument #1 ($string1) must be of type string, E given
