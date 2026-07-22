--TEST--
ReflectionProperty::setHook() on 8.4 forward profile (#22116, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php

declare(strict_types=1);

class Box {
    public string $tag {
        get => $this->__tag;
        set (string $v) { $this->__tag = $v; }
    }
    private string $__tag = 'old';
}

echo method_exists(ReflectionProperty::class, 'setHook') ? "method-yes\n" : "method-no\n";

$rp = new ReflectionProperty(Box::class, 'tag');
$hookGet = PropertyHookType::Get;
$rp->setHook($hookGet, static fn () => 'new');
$o = new Box();
echo $o->tag, "\n";
$getHook = $rp->getHook($hookGet);
echo $getHook instanceof ReflectionMethod ? "getHook-rm\n" : "getHook-no\n";
echo $getHook->getName(), "\n";
--EXPECT--
method-yes
new
getHook-rm
$tag::get
