--TEST--
stdlib token_get_all(null $flags) TypeError under strict_types JIT (#31361, ext/tokenizer/tokenizer.c)
--FILE--
<?php
declare(strict_types=1);
try {
    token_get_all('<?php echo 1;', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$tokens = token_get_all('<?php echo 1;');
echo count($tokens), "\n";
--EXPECT--
token_get_all(): Argument #2 ($flags) must be of type int, null given
5
