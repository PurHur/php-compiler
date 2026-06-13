--TEST--
stdlib mb_substr_count() multibyte substring count (VM, #4637)
--FILE--
<?php
echo function_exists('mb_substr_count') ? 'yes' : 'no', "\n";
echo mb_substr_count('αβαβα', 'α'), "\n";
echo mb_substr_count('abababa', 'ab'), "\n";
echo mb_substr_count('Hello', 'l'), "\n";
try {
    mb_substr_count('abc', '');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
yes
3
3
2
mb_substr_count(): Argument #2 ($needle) must not be empty
