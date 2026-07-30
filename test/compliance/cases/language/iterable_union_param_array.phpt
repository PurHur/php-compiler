--TEST--
iterable|string union accepts array / Traversable like Zend (#25562)
--FILE--
<?php
declare(strict_types=1);

function f(iterable|string $x): string {
    if (is_string($x)) {
        return 's';
    }
    if (is_array($x)) {
        return 'a' . count($x);
    }

    return 'i';
}

function g(string|iterable $x): string {
    if (is_string($x)) {
        return 's';
    }
    if (is_array($x)) {
        return 'a' . count($x);
    }

    return 'i';
}

echo f('x'), "\n";
echo f([1, 2]), "\n";
echo f((function () { yield 1; })()), "\n";
echo f(new ArrayIterator([1])), "\n";
echo g([9]), "\n";

try {
    f(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    f(new stdClass);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C {
    public iterable|string $p;
}
$c = new C;
$c->p = [1, 2];
echo is_array($c->p) ? "prop-arr\n" : "prop-?\n";
$c->p = new ArrayIterator([1]);
echo $c->p instanceof ArrayIterator ? "prop-ai\n" : "prop-?\n";
try {
    $c->p = new stdClass;
    echo "prop-std-leak\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'Traversable|array|string') ? "prop-std-te\n" : ("prop-std-bad: ".$e->getMessage()."\n");
}

function r(iterable|string $v): iterable|string {
    return $v;
}
echo is_array(r([1, 2])) ? "ret-arr\n" : "ret-?\n";
?>
--EXPECTF--
s
a2
i
i
a1
f(): Argument #1 ($x) must be of type Traversable|array|string, int given, called in %s on line %d
f(): Argument #1 ($x) must be of type Traversable|array|string, stdClass given, called in %s on line %d
prop-arr
prop-ai
prop-std-te
ret-arr
