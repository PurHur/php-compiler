--TEST--
ReflectionEnum source metadata + __toString implements (#22448)
--FILE--
<?php
/** enum doc */
enum E: int {
    case A = 1;
}
$r = new ReflectionEnum(E::class);
$file = $r->getFileName();
echo 'file_ok=', (is_string($file) && $file !== '' && $file !== false) ? 'yes' : 'no', "\n";
echo 'start=', var_export($r->getStartLine(), true), "\n";
echo 'end=', var_export($r->getEndLine(), true), "\n";
echo 'doc=', var_export($r->getDocComment(), true), "\n";
$s = (string) $r;
echo 'has_file=', (is_string($file) && str_contains($s, $file)) ? 'yes' : 'no', "\n";
echo 'has_UnitEnum=', str_contains($s, 'UnitEnum') ? 'yes' : 'no', "\n";
echo 'has_BackedEnum=', str_contains($s, 'BackedEnum') ? 'yes' : 'no', "\n";
--EXPECT--
file_ok=yes
start=3
end=5
doc='/** enum doc */'
has_file=yes
has_UnitEnum=yes
has_BackedEnum=yes
