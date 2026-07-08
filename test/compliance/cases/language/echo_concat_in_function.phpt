--TEST--
echo concat inside user function — plain concat not coalesce-lowered (#17378, re-#17372)
--FILE--
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

function greet(string $name): void
{
    echo $name . "\n";
}

greet('hello');
strlen('probe');
probe('after_strlen', true);
--EXPECT--
hello
after_strlen: true
