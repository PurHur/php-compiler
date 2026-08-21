--TEST--
ArrayObject isset dim + empty AS_PROPS AOT (#33079)
--FILE--
<?php
$a = new ArrayObject(['z' => 0, 'x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo isset($a['z']) ? 'T' : 'F', "\n";
echo empty($a->x) ? 'T' : 'F', "\n";
echo isset($a->z) ? 'T' : 'F', "\n";
echo empty($a->z) ? 'T' : 'F', "\n";
--EXPECT--
T
F
T
T
