--TEST--
Language: variable FCC on null/bool/array throws catchable Error (#28937, Zend/zend_execute.c)
--FILE--
<?php
function fccErr(string $label, $value): void {
    try {
        $c = $value;
        $x = $c(...);
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ':', $e->getMessage(), "\n";
    }
    echo $label, ": survived\n";
}

fccErr('null', null);
fccErr('false', false);
fccErr('true', true);
fccErr('empty', []);
fccErr('one', [1]);

try {
    $x = null(...);
    echo "lit_null: OK\n";
} catch (Throwable $e) {
    echo 'lit_null: ', get_class($e), ':', $e->getMessage(), "\n";
}
echo "lit_null: survived\n";

try {
    $x = false(...);
    echo "lit_false: OK\n";
} catch (Throwable $e) {
    echo 'lit_false: ', get_class($e), ':', $e->getMessage(), "\n";
}
echo "lit_false: survived\n";
--EXPECT--
null: Error:Value of type null is not callable
null: survived
false: Error:Value of type bool is not callable
false: survived
true: Error:Value of type bool is not callable
true: survived
empty: Error:Array callback must have exactly two elements
empty: survived
one: Error:Array callback must have exactly two elements
one: survived
lit_null: Error:Call to undefined function null()
lit_null: survived
lit_false: Error:Call to undefined function false()
lit_false: survived
