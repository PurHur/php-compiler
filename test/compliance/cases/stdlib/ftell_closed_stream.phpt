--TEST--
stdlib ftell()/fseek()/fread() on closed stream — TypeError (#5135, ext/standard/streams.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fclose($h);
foreach (['ftell', 'fseek', 'fread'] as $fn) {
    try {
        if ('ftell' === $fn) {
            $fn($h);
        } elseif ('fseek' === $fn) {
            $fn($h, 0);
        } else {
            $fn($h, 1);
        }
        echo $fn, " no throw\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
ftell: ftell(): supplied resource is not a valid stream resource
fseek: fseek(): supplied resource is not a valid stream resource
fread: fread(): supplied resource is not a valid stream resource
