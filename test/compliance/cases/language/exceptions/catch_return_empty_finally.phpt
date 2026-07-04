--TEST--
Language: return from catch with empty finally preserves value (#15738)
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
        // fused empty finally — must not drop the catch return
    }
}

echo probe(), "\n";

function single(): int {
    try {
        throw new Exception('e');
    } catch (Exception $e) {
        return 1;
    } finally {
    }
}

echo single(), "\n";
--EXPECT--
ok:a
1
