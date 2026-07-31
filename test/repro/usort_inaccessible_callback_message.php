<?php
/**
 * #25712 — usort inaccessible private/protected callback TypeError wording.
 */
class A {
    private function cmp($a, $b) {
        return $a <=> $b;
    }

    protected function cmpProt($a, $b) {
        return $a <=> $b;
    }

    public function sortInScope() {
        $arr = [3, 1, 2];
        usort($arr, [$this, 'cmp']);

        return $arr;
    }
}

class B extends A {
    public function sortProtected() {
        $arr = [3, 1, 2];
        usort($arr, [$this, 'cmpProt']);

        return $arr;
    }
}

$a = new A();
try {
    $r = $a->sortInScope();
    echo 'in-scope:', implode(',', $r), "\n";
} catch (Throwable $e) {
    echo 'in-scope:', get_class($e), ':', $e->getMessage(), "\n";
}

$arr = [3, 1, 2];
try {
    usort($arr, [$a, 'cmp']);
    echo "global-private:OK\n";
} catch (Throwable $e) {
    echo 'global-private:', get_class($e), ':', $e->getMessage(), "\n";
}

$arr = [3, 1, 2];
try {
    usort($arr, [$a, 'cmpProt']);
    echo "global-protected:OK\n";
} catch (Throwable $e) {
    echo 'global-protected:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $r = (new B())->sortProtected();
    echo 'subclass:', implode(',', $r), "\n";
} catch (Throwable $e) {
    echo 'subclass:', get_class($e), ':', $e->getMessage(), "\n";
}
