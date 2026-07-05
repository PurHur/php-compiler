<?php

declare(strict_types=1);

function gen(): Generator
{
    yield 1;
    yield 2;
}

$g = gen();
$g->rewind();
$ref = new ReflectionGenerator($g);

if ('gen' !== $ref->getFunction()->getName()) {
    echo "fail: function name mismatch\n";
    exit(1);
}

$line = $ref->getExecutingLine();
if (!\is_int($line) || $line <= 0) {
    echo 'fail: executing line='.var_export($line, true)."\n";
    exit(1);
}

$file = $ref->getExecutingFile();
if (!\is_string($file) || '' === $file) {
    echo 'fail: executing file='.var_export($file, true)."\n";
    exit(1);
}

if ($ref->getExecutingGenerator() !== $g) {
    echo "fail: getExecutingGenerator identity\n";
    exit(1);
}

try {
    new ReflectionGenerator(new stdClass());
    echo "fail: expected TypeError for stdClass\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type Generator')) {
        echo 'fail: TypeError message='.$e->getMessage()."\n";
        exit(1);
    }
}

while ($g->valid()) {
    $g->next();
}

try {
    $ref->getExecutingLine();
    echo "fail: expected ReflectionException on terminated generator\n";
    exit(1);
} catch (ReflectionException $e) {
    if (!str_contains($e->getMessage(), 'terminated Generator')) {
        echo 'fail: ReflectionException message='.$e->getMessage()."\n";
        exit(1);
    }
}

echo "ok\n";
