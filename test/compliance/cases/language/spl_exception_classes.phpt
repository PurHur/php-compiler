--TEST--
language: SPL exception classes throw, catch, class_exists (ext/spl/spl_exceptions.c, #5237)
--FILE--
<?php
echo 'RuntimeException:', class_exists('RuntimeException') ? 'yes' : 'no', "\n";
echo 'InvalidArgumentException:', class_exists('InvalidArgumentException') ? 'yes' : 'no', "\n";
echo 'DomainException:', class_exists('DomainException') ? 'yes' : 'no', "\n";
echo 'UnexpectedValueException:', class_exists('UnexpectedValueException') ? 'yes' : 'no', "\n";

try {
    throw new RuntimeException('re');
} catch (RuntimeException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

try {
    throw new InvalidArgumentException('ia');
} catch (Exception $e) {
    echo 'iae:', $e->getMessage(), "\n";
}

try {
    throw new DomainException('de');
} catch (LogicException $e) {
    echo 'de:', $e->getMessage(), "\n";
}

try {
    throw new UnexpectedValueException('uv');
} catch (RuntimeException $e) {
    echo 'uv:', $e->getMessage(), "\n";
}
--EXPECT--
RuntimeException:yes
InvalidArgumentException:yes
DomainException:yes
UnexpectedValueException:yes
caught:re
iae:ia
de:de
uv:uv
