--TEST--
Language: function try/catch return resumes caller after caught exception (#8574, Zend/zend_execute.c)
--FILE--
<?php
function probe(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ok\n";
    } catch (TypeError $e) {
        echo $label, ": ", $e->getMessage(), "\n";
    }
}
probe('substr_compare_array_haystack', static function (): void {
    substr_compare([], 'a', 0);
});
probe('substr_compare_array_needle', static function (): void {
    substr_compare('abc', [], 0);
});
echo "after\n";
--EXPECT--
substr_compare_array_haystack: substr_compare(): Argument #1 ($haystack) must be of type string, array given
substr_compare_array_needle: substr_compare(): Argument #2 ($needle) must be of type string, array given
after
