<?php
ini_set('display_errors', '0');
ini_set('error_reporting', '32767');

class A {
    #[\Deprecated(message: 'gone')]
    public const X = 1;
}
class B extends A {}

echo B::X, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "dep\n" : "no\n";
