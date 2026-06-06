--TEST--
Language: interface property hooks — implementing class uses backing field (#6620, zend_property_hooks.c)
--FILE--
<?php
interface I {
    public int $p {
        get;
        set;
    }
}
class C implements I {
    public int $p = 1;
}
interface HasTitle {
    public string $title {
        get;
    }
}
class Page implements HasTitle {
    public string $title = 'home';
}
echo (new C())->p, "\n";
echo (new Page())->title, "\n";
--EXPECT--
1
home
