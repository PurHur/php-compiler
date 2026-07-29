--TEST--
stdlib sprintf/printf/vsprintf/fprintf trailing % — ArgumentCountError/ValueError (#24661, formatted_print.c)
--FILE--
<?php
$h = fopen('php://memory', 'w+');
foreach ([
    fn () => sprintf('%'),
    fn () => sprintf('%', 1),
    fn () => vsprintf('%', []),
    fn () => vsprintf('%', [1]),
    fn () => printf('%'),
    fn () => fprintf($h, '%'),
    fn () => fprintf($h, '%', 1),
    fn () => sprintf('%s%'),
    fn () => sprintf('%s%', 'a'),
    fn () => sprintf('%s%', 'a', 1),
] as $i => $fn) {
    try {
        $fn();
        echo "ok{$i}\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ArgumentCountError:2 arguments are required, 1 given
ValueError:Missing format specifier at end of string
ValueError:The arguments array must contain 1 items, 0 given
ValueError:Missing format specifier at end of string
ArgumentCountError:2 arguments are required, 1 given
ArgumentCountError:3 arguments are required, 2 given
ValueError:Missing format specifier at end of string
ArgumentCountError:3 arguments are required, 1 given
ArgumentCountError:3 arguments are required, 2 given
ValueError:Missing format specifier at end of string
