--TEST--
Language: __get reading private via $this uses declaring scope, not child shadow (#25795)
--FILE--
<?php
class A {
    private $hidden = 'A';
    public function __get($n)
    {
        return $this->hidden;
    }
}
class B extends A {
    private $hidden = 'B';
}
echo (new B)->hidden, "\n";

// Same class: visible private must not route through __get.
class C {
    private $hidden = 'C';
    public function __get($n)
    {
        echo "should-not\n";
        return 'magic';
    }
    public function read()
    {
        return $this->hidden;
    }
}
echo (new C())->read(), "\n";
?>
--EXPECT--
A
C
