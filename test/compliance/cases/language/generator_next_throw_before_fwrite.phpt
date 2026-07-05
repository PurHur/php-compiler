--TEST--
Generator::next() throw propagates to caller catch when followed by fwrite() (#16609, re-#13989)
--FILE--
<?php
declare(strict_types=1);
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
if (1 !== $g->current()) {
    echo "bad current\n";
    exit(1);
}
try {
    $g->next();
    fwrite(STDERR, "expected next() to throw\n");
    exit(1);
} catch (Exception $e) {
    if ('x' !== $e->getMessage()) {
        echo "bad message\n";
        exit(1);
    }
}
echo "ok\n";
--EXPECT--
ok
