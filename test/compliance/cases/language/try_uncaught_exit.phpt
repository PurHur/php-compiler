--TEST--
Language: uncaught throw exits CLI with non-zero status (#57, #195, #2084)
--FILE--
<?php
class E {
    public string $message = 'boom';
}

throw new E();
echo "never\n";
--EXPECT--

--EXPECT_EXIT--
255
