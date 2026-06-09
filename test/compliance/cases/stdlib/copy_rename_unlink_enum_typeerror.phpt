--TEST--
stdlib copy/rename/unlink — enum path operands TypeError (#6280, ext/standard/file.c)
--FILE--
<?php
enum PathEnum: string { case A = 'x'; }
enum LocalUnitEnum { case A; }

$twoArgFns = ['copy', 'rename'];
foreach ($twoArgFns as $fn) {
    foreach (['backed' => PathEnum::A, 'unit' => LocalUnitEnum::A] as $label => $operand) {
        try {
            $fn($operand, '/tmp/y');
            echo "{$fn} from {$label} uncaught\n";
        } catch (TypeError $e) {
            echo "{$fn} from {$label} TypeError\n";
        }
        try {
            $fn('/tmp/x', $operand);
            echo "{$fn} to {$label} uncaught\n";
        } catch (TypeError $e) {
            echo "{$fn} to {$label} TypeError\n";
        }
    }
}

foreach (['backed' => PathEnum::A, 'unit' => LocalUnitEnum::A] as $label => $operand) {
    try {
        unlink($operand);
        echo "unlink {$label} uncaught\n";
    } catch (TypeError $e) {
        echo "unlink {$label} TypeError\n";
    }
}
--EXPECT--
copy from backed TypeError
copy to backed TypeError
copy from unit TypeError
copy to unit TypeError
rename from backed TypeError
rename to backed TypeError
rename from unit TypeError
rename to unit TypeError
unlink backed TypeError
unlink unit TypeError
