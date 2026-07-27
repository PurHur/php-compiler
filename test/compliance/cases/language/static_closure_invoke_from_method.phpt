--TEST--
language: static closure invoked from instance method has no $this (issue #23704)
--FILE--
<?php
error_reporting(E_ALL);
$f = static function () {
    echo 'isset_this=';
    var_export(isset($this));
    echo "\n";
    try {
        echo 'this_class=', get_class($this), "\n";
    } catch (Throwable $e) {
        echo 'read:', $e->getMessage(), "\n";
    }
};
class A
{
    public function t(): void
    {
        global $f;
        $f();
    }
}
(new A())->t();

// Non-static top-level closure also must not inherit the caller's $this.
$g = function () {
    echo 'ns_isset=';
    var_export(isset($this));
    echo "\n";
};
class B
{
    public function t(): void
    {
        global $g;
        $g();
    }
}
(new B())->t();

// Auto-bind when created in object context still works.
class C
{
    public int $v = 7;
    public function m(): int
    {
        $h = function () {
            return $this->v;
        };

        return $h();
    }
}
echo 'bound=', (new C())->m(), "\n";
--EXPECT--
isset_this=false
this_class=read:Using $this when not in object context
ns_isset=false
bound=7
