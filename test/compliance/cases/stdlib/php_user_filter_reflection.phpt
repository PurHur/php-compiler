--TEST--
stdlib php_user_filter Reflection names + filter arity (#25584)
--FILE--
<?php
declare(strict_types=1);
$r = new ReflectionClass('php_user_filter');
$parts = [];
foreach ($r->getMethods() as $m) {
    $params = [];
    foreach ($m->getParameters() as $p) {
        $params[] = ($p->isPassedByReference() ? '&' : '')
            . $p->getName()
            . ($p->hasType() ? ':' . $p->getType() : '');
    }
    $parts[] = $m->getName() . '(' . implode(',', $params) . ')';
}
echo implode('|', $parts), "\n";
class UF extends php_user_filter {
    public function filter($in, $out, &$consumed, $closing): int {
        return PSFS_PASS_ON;
    }
}
echo "subclass_ok\n";
?>
--EXPECT--
filter(in,out,&consumed,closing:bool)|onCreate()|onClose()
subclass_ok
