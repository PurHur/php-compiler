--TEST--
Interface abstract property hooks — implementing class uses backing field (#4834, #6620, Zend/zend_compile.c)
--FILE--
<?php
interface HasTitle {
    public string $title {
        get;
    }
}
class Page implements HasTitle {
    public string $title = 'home';
}
echo (new Page())->title, "\n";
--EXPECT--
home
