--TEST--
str_ireplace/substr_replace/strtr Reflection types (VM, issue #23588, string.stub.php)
--FILE--
<?php
foreach (['str_replace', 'str_ireplace', 'substr_replace', 'strtr', 'substr_count'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '(none)';
        $ref = $p->isPassedByReference() ? '&' : '';
        $opt = $p->isOptional() ? '?' : '';
        echo '  ', $ref, $opt, $p->getName(), ' ', $t, "\n";
    }
}
$r = str_ireplace('A', 'b', 'Aa');
$s = substr_replace('abcdef', 'X', 2, 1);
$t = strtr('abc', ['a' => 'A']);
$c = substr_count('abab', 'ab');
echo 'ireplace=', $r, "\n";
echo 'substr_replace=', $s, "\n";
echo 'strtr=', $t, "\n";
echo 'substr_count=', $c, "\n";
?>
--EXPECT--
str_replace ret=array|string
  search array|string
  replace array|string
  subject array|string
  &?count (none)
str_ireplace ret=array|string
  search array|string
  replace array|string
  subject array|string
  &?count (none)
substr_replace ret=array|string
  string array|string
  replace array|string
  offset array|int
  ?length array|int|null
strtr ret=string
  string string
  from array|string
  ?to ?string
substr_count ret=int
  haystack string
  needle string
  ?offset int
  ?length ?int
ireplace=bb
substr_replace=abXdef
strtr=Abc
substr_count=2
