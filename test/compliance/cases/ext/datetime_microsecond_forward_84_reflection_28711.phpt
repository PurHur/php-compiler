--TEST--
ext date DateTime microsecond Reflection returns PROFILE≥8.4 (VM, issue #28711)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['DateTime', 'DateTimeImmutable'] as $c) {
    foreach (['createFromTimestamp', 'getMicrosecond', 'setMicrosecond'] as $m) {
        $r = new ReflectionMethod($c, $m);
        echo "$c::$m ret=", $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
        if (method_exists($r, 'hasTentativeReturnType') && $r->hasTentativeReturnType()) {
            echo ' tentative=', (string) $r->getTentativeReturnType();
        }
        echo PHP_EOL;
    }
}
?>
--EXPECT--
DateTime::createFromTimestamp ret=none tentative=static
DateTime::getMicrosecond ret=int
DateTime::setMicrosecond ret=static
DateTimeImmutable::createFromTimestamp ret=none tentative=static
DateTimeImmutable::getMicrosecond ret=int
DateTimeImmutable::setMicrosecond ret=static
