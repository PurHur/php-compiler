--TEST--
RegexIterator::__construct empty/null/invalid pattern → InvalidArgumentException (#31511, php-src-strict)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'WARN: ', $m, "\n";

    return true;
});

foreach (['', null, '('] as $pattern) {
    echo 'CASE=', var_export($pattern, true), "\n";
    try {
        new RegexIterator(new ArrayIterator(['a']), $pattern);
        echo "constructed\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$r = new ReflectionMethod(RegexIterator::class, '__construct');
echo 'params=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
try {
    new RegexIterator(iterator: new ArrayIterator(['a']), pattern: '/a/');
    echo "named_ok\n";
} catch (Throwable $e) {
    echo 'named: ', $e->getMessage(), "\n";
}
--EXPECT--
CASE=''
InvalidArgumentException: RegexIterator::__construct(): Empty regular expression
CASE=NULL
WARN: RegexIterator::__construct(): Passing null to parameter #2 ($pattern) of type string is deprecated
InvalidArgumentException: RegexIterator::__construct(): Empty regular expression
CASE='('
InvalidArgumentException: RegexIterator::__construct(): No ending matching delimiter ')' found
params=iterator,pattern,mode,flags,pregFlags
named_ok
