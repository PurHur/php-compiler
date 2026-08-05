--TEST--
grapheme_* Reflection + Zend named args (VM, issue #27884)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
foreach (['grapheme_strlen', 'grapheme_substr', 'grapheme_strstr', 'grapheme_extract'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        if ($p->isOptional()) {
            echo ' OPT';
            if ($p->isDefaultValueAvailable()) {
                echo '=', json_encode($p->getDefaultValue());
            }
        } else {
            echo ' REQ';
        }
        if ($p->isPassedByReference()) {
            echo ' REF';
        }
        echo "\n";
    }
}
try {
    echo 'named_strlen=', grapheme_strlen(string: 'é'), "\n";
} catch (Throwable $e) {
    echo 'named_strlen=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'named_substr=', grapheme_substr(string: 'abcdef', offset: 2, length: 2), "\n";
} catch (Throwable $e) {
    echo 'named_substr=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'named_strstr=', grapheme_strstr(haystack: 'abcdef', needle: 'cd', beforeNeedle: false), "\n";
} catch (Throwable $e) {
    echo 'named_strstr=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    grapheme_strlen(str: 'x');
    echo "legacy_str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
grapheme_strlen ret=int|false|null
  string $string REQ
grapheme_substr ret=string|false
  string $string REQ
  int $offset REQ
  ?int $length OPT=null
grapheme_strstr ret=string|false
  string $haystack REQ
  string $needle REQ
  bool $beforeNeedle OPT=false
grapheme_extract ret=string|false
  string $haystack REQ
  int $size REQ
  int $type OPT=0
  int $offset OPT=0
  $next OPT=null REF
named_strlen=1
named_substr=cd
named_strstr=cdef
Unknown named parameter $str
