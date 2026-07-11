--TEST--
Language: return from catch with fused empty finally/end block (#15738)
--FILE--
<?php
class E1 extends Exception {}
class E2 extends Exception {}

function probe(): string {
    try {
        throw new E1('a');
    } catch (E2 $e) {
        return 'wrong';
    } catch (E1 $e) {
        return 'ok:' . $e->getMessage();
    } finally {
        // empty finally fused with end block
    }
}

echo probe(), "\n";
--EXPECT--
ok:a
