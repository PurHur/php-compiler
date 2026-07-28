--TEST--
AOT: Core UPLOAD_ERR_*/PEAR_*/ZEND_* defined() matches get_defined_constants (#24081)
--FILE--
<?php
// Avoid foreach+defined() — AOT heap corruption is pre-existing (php_core_constants.phpt also red on master).
$core = get_defined_constants(true)['Core'];
$ok = array_key_exists('UPLOAD_ERR_OK', $core) && defined('UPLOAD_ERR_OK');
$ok = $ok && array_key_exists('UPLOAD_ERR_NO_FILE', $core) && defined('UPLOAD_ERR_NO_FILE');
$ok = $ok && array_key_exists('DEFAULT_INCLUDE_PATH', $core) && defined('DEFAULT_INCLUDE_PATH');
$ok = $ok && array_key_exists('PEAR_INSTALL_DIR', $core) && defined('PEAR_INSTALL_DIR');
$ok = $ok && array_key_exists('PEAR_EXTENSION_DIR', $core) && defined('PEAR_EXTENSION_DIR');
$ok = $ok && array_key_exists('ZEND_THREAD_SAFE', $core) && defined('ZEND_THREAD_SAFE');
$ok = $ok && array_key_exists('ZEND_DEBUG_BUILD', $core) && defined('ZEND_DEBUG_BUILD');
echo $ok ? "orphans_ok\n" : "orphans_bad\n";
echo (UPLOAD_ERR_OK === 0 && UPLOAD_ERR_NO_FILE === 4) ? "upload_ok\n" : "upload_bad\n";
echo (is_string(constant('DEFAULT_INCLUDE_PATH')) && constant('DEFAULT_INCLUDE_PATH') !== '') ? "path_ok\n" : "path_bad\n";
echo (is_bool(ZEND_THREAD_SAFE) && is_bool(ZEND_DEBUG_BUILD)) ? "zend_ok\n" : "zend_bad\n";
?>
--EXPECT--
orphans_ok
upload_ok
path_ok
zend_ok
