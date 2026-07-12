<?php
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
