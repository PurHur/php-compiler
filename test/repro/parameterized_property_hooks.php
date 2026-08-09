<?php
// #29444 — Zend rejects get($param); previously accepted as #18172 extension.
declare(strict_types=1);

class C {
    private string $_data = 'abcdef';

    public string $chunk {
        get ($len) {
            return substr($this->_data, 0, $len);
        }
    }
}

echo 'PASS_PARAMETERIZED_HOOK:', (new C())->chunk(3), "\n";
