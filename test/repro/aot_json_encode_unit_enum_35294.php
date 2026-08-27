<?php
/** Repro #35294 — AOT json_encode(unit enum) must match Zend, not abort compile. */
enum Suit
{
    case Hearts;
}

enum Color: string
{
    case Red = 'r';
}

$r = json_encode(Suit::Hearts);
var_dump($r);
var_dump(json_last_error());
var_dump(json_last_error_msg());

try {
    json_encode(Suit::Hearts, JSON_THROW_ON_ERROR);
    echo "THROW_MISSING\n";
} catch (JsonException $e) {
    echo 'EX:', $e->getMessage(), "\n";
}

echo 'backed:', json_encode(Color::Red), "\n";
