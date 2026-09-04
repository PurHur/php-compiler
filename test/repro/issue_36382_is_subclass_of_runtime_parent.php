<?php
// Slim ErrorMiddleware::getErrorHandler shape — is_subclass_of($type, $class) with
// foreach key parent (#36382). php-src: Zend/zend_builtin_functions.c zend_is_class_or_interface
class Base {}
class Child extends Base {}
$handlers = ['Base' => true];
$type = 'Child';
$ok = false;
foreach ($handlers as $class => $handler) {
    if (is_subclass_of($type, $class)) {
        $ok = true;
    }
}
echo $ok ? 'ok' : 'fail';
