--TEST--
stdlib highlight_string(null $return) TypeError under strict_types JIT (#31383, ext/standard/url_scanner_ex.re)
--FILE--
<?php
declare(strict_types=1);
try {
    highlight_string('<?php', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
highlight_string(): Argument #2 ($return) must be of type bool, null given
