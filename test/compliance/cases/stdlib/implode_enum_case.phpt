--TEST--
stdlib implode() rejects backed enum case elements (#5581, ext/standard/string.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
foreach ([
    'inline' => [E::A, E::B],
    'variable' => (static function (): array {
        return [E::A, E::B];
    })(),
] as $label => $input) {
    try {
        echo implode(',', $input), "\n";
        echo $label, ": ok\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
inline: Error: Object of class E could not be converted to string
variable: Error: Object of class E could not be converted to string
