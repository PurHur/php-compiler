<?php
/**
 * Repro #25947 — enum method ?self / self|null must accept returning a case.
 */
enum E: int {
    case A = 1;
    public static function plain(): self { return self::A; }
    public static function nullable(): ?self { return self::A; }
    public static function union(): self|null { return self::A; }
    public static function nullOnly(): ?self { return null; }
}
echo "plain=", E::plain()->value, "\n";
try {
    echo "nullable=", E::nullable()->value, "\n";
} catch (Throwable $e) {
    echo "nullable: ", get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    echo "union=", E::union()->value, "\n";
} catch (Throwable $e) {
    echo "union: ", get_class($e), ": ", $e->getMessage(), "\n";
}
echo "nullOnly=", var_export(E::nullOnly(), true), "\n";
