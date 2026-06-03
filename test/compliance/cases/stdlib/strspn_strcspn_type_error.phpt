--TEST--
stdlib strspn()/strcspn() — TypeError for non-string operands (ext/standard/string.c)
--FILE--
<?php
foreach ([[], new stdClass()] as $bad) {
    try {
        strspn($bad, 'a');
        echo "strspn haystack no throw\n";
    } catch (Throwable $e) {
        echo 'strspn haystack: ', $e::class, ': ', $e->getMessage(), "\n";
    }
    try {
        strspn('a', $bad);
        echo "strspn mask no throw\n";
    } catch (Throwable $e) {
        echo 'strspn mask: ', $e::class, ': ', $e->getMessage(), "\n";
    }
    try {
        strcspn($bad, 'a');
        echo "strcspn haystack no throw\n";
    } catch (Throwable $e) {
        echo 'strcspn haystack: ', $e::class, ': ', $e->getMessage(), "\n";
    }
    try {
        strcspn('a', $bad);
        echo "strcspn mask no throw\n";
    } catch (Throwable $e) {
        echo 'strcspn mask: ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
echo strspn('abc', 'a'), "\n";
--EXPECT--
strspn haystack: TypeError: strspn(): Argument #1 ($string) must be of type string, array given
strspn mask: TypeError: strspn(): Argument #2 ($characters) must be of type string, array given
strcspn haystack: TypeError: strcspn(): Argument #1 ($string) must be of type string, array given
strcspn mask: TypeError: strcspn(): Argument #2 ($characters) must be of type string, array given
strspn haystack: TypeError: strspn(): Argument #1 ($string) must be of type string, stdClass given
strspn mask: TypeError: strspn(): Argument #2 ($characters) must be of type string, stdClass given
strcspn haystack: TypeError: strcspn(): Argument #1 ($string) must be of type string, stdClass given
strcspn mask: TypeError: strcspn(): Argument #2 ($characters) must be of type string, stdClass given
1
