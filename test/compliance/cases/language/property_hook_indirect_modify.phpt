--TEST--
Hooked property indirect modification dispatches get/set hooks (#6775, zend_property_hooks.c)
--FILE--
<?php
class C {
    public array $items {
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$c = new C();
$c->items[] = 1;
var_export($c->items);
echo "\n";
$c->items['k'] = 'v';
var_export($c->items);
echo "\n";
--EXPECT--
array (
  0 => 1,
)
array (
  0 => 1,
  'k' => 'v',
)
