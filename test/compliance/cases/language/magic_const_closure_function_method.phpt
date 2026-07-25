--TEST--
Language: __FUNCTION__/__METHOD__ inside closures are {closure} (#22832)
--FILE--
<?php
$free = (function () {
    return [__FUNCTION__, __METHOD__];
})();
var_export($free);
echo "\n";

$arrow = (fn () => [__FUNCTION__, __METHOD__])();
var_export($arrow);
echo "\n";

class C {
    public function m(): array {
        $inner = function () {
            return [__FUNCTION__, __METHOD__, __CLASS__];
        };

        return $inner();
    }

    public static function s(): array {
        $inner = fn () => [__FUNCTION__, __METHOD__, __CLASS__];

        return $inner();
    }
}

var_export((new C)->m());
echo "\n";
var_export(C::s());
echo "\n";
--EXPECT--
array (
  0 => '{closure}',
  1 => '{closure}',
)
array (
  0 => '{closure}',
  1 => '{closure}',
)
array (
  0 => '{closure}',
  1 => '{closure}',
  2 => 'C',
)
array (
  0 => '{closure}',
  1 => '{closure}',
  2 => 'C',
)
