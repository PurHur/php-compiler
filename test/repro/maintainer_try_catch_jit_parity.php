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
        // must run before return
    }
}

echo probe(), "\n";
