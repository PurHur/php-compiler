--TEST--
stdlib register_shutdown_function() accepts invokable objects and array callables (#12402)
--FILE--
<?php
class Invokable {
    public function __invoke(): void { echo "invokable\n"; }
}
class C {
    public function method(): void { echo "method\n"; }
}
register_shutdown_function(new Invokable());
register_shutdown_function([new C(), 'method']);
echo "before\n";
--EXPECT--
before
invokable
method
--EXPECT_EXIT--
0
