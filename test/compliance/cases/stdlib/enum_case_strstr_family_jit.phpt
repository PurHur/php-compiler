--TEST--
stdlib strpbrk/stristr/strstr/strchr/strrchr JIT — enum case operands TypeError (#5905)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'hello';
}

foreach (['strpbrk', 'stristr', 'strstr', 'strchr', 'strrchr'] as $fn) {
    try {
        $fn(E::A, 'l');
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ": ", $e->getMessage(), "\n";
    }
}
try {
    strpbrk('hello', E::A);
    echo "strpbrk needle: uncaught\n";
} catch (TypeError $e) {
    echo "strpbrk needle: ", $e->getMessage(), "\n";
}
try {
    strstr('hello', E::A);
    echo "strstr needle: uncaught\n";
} catch (TypeError $e) {
    echo "strstr needle: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
strpbrk: strpbrk(): Argument #1 ($string) must be of type string, E given
stristr: stristr(): Argument #1 ($haystack) must be of type string, E given
strstr: strstr(): Argument #1 ($haystack) must be of type string, E given
strchr: strchr(): Argument #1 ($haystack) must be of type string, E given
strrchr: strrchr(): Argument #1 ($haystack) must be of type string, E given
strpbrk needle: strpbrk(): Argument #2 ($characters) must be of type string, E given
strstr needle: strstr(): Argument #2 ($needle) must be of type string, E given
