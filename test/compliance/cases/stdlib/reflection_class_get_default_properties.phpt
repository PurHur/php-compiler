--TEST--
ReflectionClass::getDefaultProperties() returns declared property defaults (#11441)
--FILE--
<?php
declare(strict_types=1);

class RcDefaultPropsC {
    public int $count = 5;
    private string $label = 'probe';
    public int $unset;
}

$d = (new ReflectionClass(RcDefaultPropsC::class))->getDefaultProperties();
echo 5 === $d['count'] && 'probe' === $d['label'] && !isset($d['unset']) ? "defaults_ok\n" : "defaults_bad\n";
--EXPECT--
defaults_ok
