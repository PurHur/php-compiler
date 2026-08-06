<?php
// #27921 — Zend zend_execute_API.c wording for undefined static method.
class C {}
try {
    C::nope();
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
