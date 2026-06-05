<?php

declare(strict_types=1);

enum E: string
{
    case A = 'hi';
}

$tests = [
    static fn () => str_pad(E::A, 5),
    static fn () => addslashes(E::A),
    static fn () => nl2br(E::A),
    static fn () => ucfirst(E::A),
];

foreach ($tests as $i => $fn) {
    try {
        $fn();
        echo "test{$i}: uncaught\n";
    } catch (TypeError $e) {
        echo "test{$i}: TypeError\n";
    } catch (Throwable $e) {
        echo 'test' . $i . ': ' . $e::class . "\n";
    }
}
