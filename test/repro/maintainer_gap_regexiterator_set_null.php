<?php
/**
 * Maintainer gap: RegexIterator::setMode/setFlags/setPregFlags(null).
 * Zend: E_DEPRECATED + soft-coerce null→0
 * VM: TypeError (int required)
 */
error_reporting(E_ALL);

function run(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label . ": ok\n";
    } catch (Throwable $e) {
        echo $label . ': ' . get_class($e) . ':' . $e->getMessage() . "\n";
    }
}

$it = new RegexIterator(new ArrayIterator(['a1', 'b2']), '/\d/');

run('setMode', function () use ($it) {
    $it->setMode(null);
    echo 'mode=' . $it->getMode() . "\n";
});

run('setFlags', function () use ($it) {
    $it->setFlags(null);
    echo 'flags=' . $it->getFlags() . "\n";
});

run('setPregFlags', function () use ($it) {
    $it->setPregFlags(null);
    echo 'preg=' . $it->getPregFlags() . "\n";
});
