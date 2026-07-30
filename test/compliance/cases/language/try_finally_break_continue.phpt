--TEST--
Language: break/continue in try still run finally (Zend/zend_vm_def.h, #25240)
--FILE--
<?php
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        if ($i === 1) {
            continue;
        }
        $out .= "B$i";
    } finally {
        $out .= "F$i";
    }
}
echo $out, "\n";
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        $out .= "B$i";
        if ($i === 1) {
            break;
        }
    } finally {
        $out .= "F$i";
    }
}
echo $out, "\n";
$out = "";
for ($i = 0; $i < 3; $i++) {
    try {
        try {
            if ($i === 1) {
                continue;
            }
            $out .= "B$i";
        } finally {
            $out .= "I$i";
        }
    } finally {
        $out .= "O$i";
    }
}
echo $out, "\n";
--EXPECT--
B0F0F1B2F2
B0F0B1F1
B0I0O0I1O1B2I2O2
