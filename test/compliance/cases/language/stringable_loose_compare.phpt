--TEST--
Language: Stringable object loose compare / in_array / array_search (#12055, Zend/zend_compare.c)
--FILE--
<?php
class S implements Stringable {
    public function __toString(): string {
        return 'needle';
    }
}

$o = new S();
$haystack = ['needle'];

echo ($o == 'needle') ? "eq_yes\n" : "eq_no\n";
echo in_array($o, $haystack, false) ? "in_loose_yes\n" : "in_loose_no\n";
$search = array_search($o, $haystack, false);
echo (false !== $search && 0 === $search) ? "search_0\n" : "search_miss\n";
echo ($o <=> 'needle'), "\n";
echo ($o === 'needle') ? "ident_yes\n" : "ident_no\n";
echo in_array($o, $haystack, true) ? "in_strict_yes\n" : "in_strict_no\n";
?>
--EXPECT--
eq_yes
in_loose_yes
search_0
0
ident_no
in_strict_no
