<?php
class C {
    const RED = 'red';
    private const SECRET = 'secret';
}
$n = 'RED';
$o = new C();
echo 'class:', C::{$n}, "\n";
echo 'obj:', $o::{$n}, "\n";
$bad = 'MISSING';
try {
    echo C::{$bad};
} catch (Error $e) {
    echo 'err:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo $o::{$bad};
} catch (Error $e) {
    echo 'err2:', get_class($e), ':', $e->getMessage(), "\n";
}
$priv = 'SECRET';
try {
    echo C::{$priv};
} catch (Error $e) {
    echo 'priv:', get_class($e), ':', $e->getMessage(), "\n";
}
