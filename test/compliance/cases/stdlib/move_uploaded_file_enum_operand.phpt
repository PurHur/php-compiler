--TEST--
stdlib move_uploaded_file() — enum case path operands TypeError (#6278, ext/standard/basic_functions.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'tmp'; }
enum LocalE { case B; }

foreach (['backed' => E::A, 'unit' => LocalE::B] as $label => $from) {
    try {
        move_uploaded_file($from, '/tmp/x');
        echo "{$label} uncaught\n";
    } catch (TypeError $e) {
        echo "{$label} TypeError\n";
    } catch (LogicException $e) {
        echo "{$label} LogicException\n";
    }
}

try {
    move_uploaded_file('/tmp/from', LocalE::B);
    echo "to uncaught\n";
} catch (TypeError $e) {
    echo "to TypeError\n";
} catch (LogicException $e) {
    echo "to LogicException\n";
}
--EXPECT--
backed TypeError
unit TypeError
to TypeError
