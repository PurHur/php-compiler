<?php
error_reporting(E_ALL);

abstract class A {}
try {
    new A();
    echo "new_abstract_ok\n";
} catch (Throwable $e) {
    echo 'abstract=', $e->getLine(), " ", $e->getMessage(), "\n";
}

interface I {}
try {
    new I();
    echo "new_interface_ok\n";
} catch (Throwable $e) {
    echo 'interface=', $e->getLine(), " ", $e->getMessage(), "\n";
}

trait T {}
try {
    new T();
    echo "new_trait_ok\n";
} catch (Throwable $e) {
    echo 'trait=', $e->getLine(), " ", $e->getMessage(), "\n";
}
