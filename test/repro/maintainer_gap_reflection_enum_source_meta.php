<?php
/** enum doc */
enum E: int {
    case A = 1;
}
$r = new ReflectionEnum(E::class);
echo "file=", var_export($r->getFileName(), true), "\n";
echo "start=", var_export($r->getStartLine(), true), "\n";
echo "end=", var_export($r->getEndLine(), true), "\n";
echo "doc=", var_export($r->getDocComment(), true), "\n";
$s = (string) $r;
echo "has_file=", (str_contains($s, __FILE__) || str_contains($s, "maintainer_gap_reflection_enum_source_meta")) ? "yes" : "no", "\n";
echo "has_BackedEnum=", str_contains($s, "BackedEnum") ? "yes" : "no", "\n";
echo "has_UnitEnum=", str_contains($s, "UnitEnum") ? "yes" : "no", "\n";
echo "has_doc=", str_contains($s, "enum doc") || $r->getDocComment() === '/** enum doc */' ? "yes" : "no", "\n";
