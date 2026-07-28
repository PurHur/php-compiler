--TEST--
Stdlib: Core UPLOAD_ERR_*/PEAR_*/ZEND_* defined() matches get_defined_constants (#24081)
--FILE--
<?php
$names = [
    'UPLOAD_ERR_OK',
    'UPLOAD_ERR_INI_SIZE',
    'UPLOAD_ERR_FORM_SIZE',
    'UPLOAD_ERR_PARTIAL',
    'UPLOAD_ERR_NO_FILE',
    'UPLOAD_ERR_NO_TMP_DIR',
    'UPLOAD_ERR_CANT_WRITE',
    'UPLOAD_ERR_EXTENSION',
    'DEFAULT_INCLUDE_PATH',
    'PEAR_INSTALL_DIR',
    'PEAR_EXTENSION_DIR',
    'ZEND_THREAD_SAFE',
    'ZEND_DEBUG_BUILD',
];
$core = get_defined_constants(true)['Core'] ?? [];
$orphans = 0;
foreach ($names as $n) {
    $inBucket = array_key_exists($n, $core);
    $isDefined = defined($n);
    if ($inBucket && !$isDefined) {
        $orphans++;
        echo $n, " orphan\n";
        continue;
    }
    if (!$isDefined) {
        echo $n, " missing\n";
        continue;
    }
    if (!$inBucket) {
        echo $n, " no-bucket\n";
        continue;
    }
    if ($core[$n] !== constant($n)) {
        echo $n, " mismatch\n";
        continue;
    }
}
echo $orphans === 0 ? "orphans_ok\n" : "orphans_bad\n";
echo defined('upload_err_ok') ? "case_bad\n" : "case_ok\n";
echo UPLOAD_ERR_OK === 0 && UPLOAD_ERR_NO_FILE === 4 ? "upload_ok\n" : "upload_bad\n";
echo is_string(DEFAULT_INCLUDE_PATH) && DEFAULT_INCLUDE_PATH !== '' ? "path_ok\n" : "path_bad\n";
echo is_bool(ZEND_THREAD_SAFE) && is_bool(ZEND_DEBUG_BUILD) ? "zend_ok\n" : "zend_bad\n";
?>
--EXPECT--
orphans_ok
case_ok
upload_ok
path_ok
zend_ok
