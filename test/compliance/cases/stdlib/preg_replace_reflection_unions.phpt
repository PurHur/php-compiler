--TEST--
Stdlib: preg_replace/filter/callback Reflection array|string; count untyped (#23587)
--FILE--
<?php
foreach (['preg_replace', 'preg_filter', 'preg_replace_callback'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, "\n";
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '(none)',
            ' byref=', (int) $p->isPassedByReference(),
            "\n";
    }
}
set_error_handler(static function (int $n, string $m): bool {
    if ($n === E_DEPRECATED && str_contains($m, 'preg_filter')) {
        echo 'DEP: ', $m, "\n";
    }
    return true;
});
@preg_filter(null, 'a', 'b');
?>
--EXPECT--
preg_replace
  pattern type=array|string byref=0
  replacement type=array|string byref=0
  subject type=array|string byref=0
  limit type=int byref=0
  count type=(none) byref=1
preg_filter
  pattern type=array|string byref=0
  replacement type=array|string byref=0
  subject type=array|string byref=0
  limit type=int byref=0
  count type=(none) byref=1
preg_replace_callback
  pattern type=array|string byref=0
  callback type=callable byref=0
  subject type=array|string byref=0
  limit type=int byref=0
  count type=(none) byref=1
  flags type=int byref=0
DEP: preg_filter(): Passing null to parameter #1 ($pattern) of type array|string is deprecated
