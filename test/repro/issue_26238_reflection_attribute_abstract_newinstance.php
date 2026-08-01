<?php
// #26238 — ReflectionAttribute::newInstance must Error on abstract attribute class
// (php-src ext/reflection/php_reflection.c — same as cannot instantiate abstract).

#[\Attribute]
abstract class Issue26238Attr {}

#[Issue26238Attr]
class Issue26238Target {}

try {
    $inst = (new ReflectionClass(Issue26238Target::class))->getAttributes()[0]->newInstance();
    echo 'FAIL: newInstance succeeded: ', get_class($inst), "\n";
} catch (Error $e) {
    echo 'OK: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'FAIL: ', get_class($e), ': ', $e->getMessage(), "\n";
}
