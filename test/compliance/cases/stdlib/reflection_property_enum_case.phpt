--TEST--
ReflectionProperty on enum — name/value pseudo-properties (issue #5680, php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

enum Unit { case A; }
enum Backed: string { case A = 'a'; }

$rName = new ReflectionProperty(Backed::class, 'name');
echo 'backed_name=', var_export($rName->getValue(Backed::A), true), "\n";
echo 'backed_name_public=', $rName->isPublic() ? '1' : '0', "\n";
echo 'backed_name_readonly=', $rName->isReadOnly() ? '1' : '0', "\n";

$rValue = new ReflectionProperty(Backed::class, 'value');
echo 'backed_value=', var_export($rValue->getValue(Backed::A), true), "\n";

$uName = new ReflectionProperty(Unit::class, 'name');
echo 'unit_name=', var_export($uName->getValue(Unit::A), true), "\n";

try {
    new ReflectionProperty(Unit::class, 'value');
    echo "unit_value: OK\n";
} catch (ReflectionException $e) {
    echo 'unit_value: ', $e->getMessage(), "\n";
}

try {
    new ReflectionProperty(Backed::class, 'bogus');
    echo "bogus: OK\n";
} catch (ReflectionException $e) {
    echo 'bogus: ', $e->getMessage(), "\n";
}

try {
    $rName->getValue(new stdClass());
    echo "wrong_object: OK\n";
} catch (ReflectionException $e) {
    echo 'wrong_object: ', $e->getMessage(), "\n";
}
--EXPECT--
backed_name='A'
backed_name_public=1
backed_name_readonly=1
backed_value='a'
unit_name='A'
unit_value: Property Unit::$value does not exist
bogus: Property Backed::$bogus does not exist
wrong_object: Given object is not an instance of the class this property was declared in
