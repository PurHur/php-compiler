--TEST--
stdlib hash_pbkdf2() 7th $options — arity, Reflection, digest (#23595, ext/hash/hash.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('hash_pbkdf2');
echo $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$omit = hash_pbkdf2('sha256', 'p', 's', 1000, 16, false);
$empty = hash_pbkdf2('sha256', 'p', 's', 1000, 16, false, []);
$named = hash_pbkdf2(
    algo: 'sha256',
    password: 'p',
    salt: 's',
    iterations: 1000,
    length: 16,
    binary: false,
    options: []
);
// Unknown keys are ignored for sha256 (passed to hash_init; php-src ops->hash_init).
$seed = hash_pbkdf2('sha256', 'p', 's', 1000, 16, false, ['seed' => 1]);
echo substr($omit, 0, 8), "\n";
echo ($omit === $empty && $omit === $named && $omit === $seed) ? "match\n" : "mismatch\n";
try {
    hash_pbkdf2('sha256', 'p', 's', 1000, 16, false, 'x');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash_pbkdf2('sha256', 'p', 's', 1000, 16, false, [], []);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
7
algo
password
salt
iterations
length
binary
options
07f5f5e2
match
hash_pbkdf2(): Argument #7 ($options) must be of type array, string given
hash_pbkdf2() expects at most 7 arguments, 8 given
