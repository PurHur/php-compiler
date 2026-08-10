--TEST--
stdlib stristr/strchr/strrchr() JIT — null $haystack TypeError under strict_types (#29783, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach (['stristr', 'strchr', 'strrchr'] as $fn) {
    try {
        $fn(null, 'a');
        echo "fail:$fn\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
stristr:TypeError:stristr(): Argument #1 ($haystack) must be of type string, null given
strchr:TypeError:strchr(): Argument #1 ($haystack) must be of type string, null given
strrchr:TypeError:strrchr(): Argument #1 ($haystack) must be of type string, null given
