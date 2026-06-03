<?php
class Base5271 { public $inherited = 42; }
class Child5271 extends Base5271 { public $own = 7; private $hidden = 1; }

$v = get_class_vars(Child5271::class);
echo count($v) === 2 && $v['inherited'] === 42 && $v['own'] === 7 ? 'ok' : 'fail';
echo isset($v['hidden']) ? ' leak' : '';
