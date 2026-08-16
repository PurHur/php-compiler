--TEST--
RegexIterator::setMode invalid mode ValueError cites setMode arg #1 (#31573)
--FILE--
<?php
$r = new RegexIterator(new ArrayIterator(['a']), '/a/');
try {
    $r->setMode(99);
    echo "UNEXPECTED_OK\n";
} catch (Throwable $t) {
    echo get_class($t), ':', $t->getMessage(), "\n";
}
--EXPECT--
ValueError:RegexIterator::setMode(): Argument #1 ($mode) must be RegexIterator::MATCH, RegexIterator::GET_MATCH, RegexIterator::ALL_MATCHES, RegexIterator::SPLIT, or RegexIterator::REPLACE
