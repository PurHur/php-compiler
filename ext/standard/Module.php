<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;

class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new str_repeat(),
            new decbin(),
            new abs(),
            new ceil(),
            new floor(),
            new round(),
            new number_format(),
            new sqrt(),
            new pi(),
            new deg2rad(),
            new rad2deg(),
            new log(),
            new exp(),
            new sin(),
            new cos(),
            new tan(),
            new is_nan(),
            new is_finite(),
            new is_infinite(),
            new pow(),
            new hypot(),
            new atan2(),
            new fmod(),
            new fdiv(),
            new intval(),
            new floatval(),
            new doubleval(),
            new boolval(),
            new var_export(),
            new gettype(),
            new get_debug_type(),
            new strval(),
            new int_min(),
            new int_max(),
            new intdiv(),
            new ord(),
            new pack(),
            new chr(),
            new strcmp(),
            new levenshtein(),
            new similar_text(),
            new soundex(),
            new metaphone(),
            new strnatcmp(),
            new strnatcasecmp(),
            new strcasecmp(),
            new strncasecmp(),
            new strspn(),
            new strcspn(),
            new strpbrk(),
            new dechex(),
            new hexdec(),
            new decoct(),
            new octdec(),
            new bindec(),
            new is_numeric(),
            new is_scalar(),
            new lcfirst(),
            new ucfirst(),
            new ucwords(),
            new strtolower(),
            new strtoupper(),
            new string_trim(),
            new string_ltrim(),
            new string_rtrim(),
            new substr(),
            new strrev(),
            new str_rot13(),
            new str_shuffle(),
            new strpos(),
            new strstr(),
            new strchr(),
            new stristr(),
            new strrchr(),
            new stripos(),
            new strrpos(),
            new substr_count(),
            new str_word_count(),
            new str_contains(),
            new str_starts_with(),
            new str_ends_with(),
            new strncmp(),
            new substr_compare(),
            new array_count(),
            new array_count('sizeof'),
            new array_key_exists(),
            new array_key_first(),
            new array_key_last(),
            new array_is_list(),
            new in_array(),
            new array_push(),
            new array_pop(),
            new array_shift(),
            new array_unshift(),
            new sort_(),
            new rsort_(),
            new shuffle_(),
            new array_rand(),
            new ksort_(),
            new krsort_(),
            new asort_(),
            new natsort_(),
            new natcasesort_(),
            new arsort_(),
            new array_multisort(),
            new usort_(),
            new uasort_(),
            new sprintf_(),
            new array_values(),
            new array_keys(),
            new array_merge(),
            new array_slice(),
            new array_splice(),
            new array_chunk(),
            new array_column(),
            new explode(),
            new implode(),
            new implode('join'),
            new str_replace(),
            new str_ireplace(),
            new strtr(),
            new preg_quote(),
            new quotemeta(),
            new addslashes(),
            new stripslashes(),
            new preg_match(),
            new preg_match_all(),
            new preg_grep(),
            new preg_replace(),
            new preg_replace_callback(),
            new preg_split(),
            new preg_last_error_(),
            new nl2br(),
            new array_reverse(),
            new array_search(),
            new array_sum(),
            new array_product(),
            new array_flip(),
            new array_change_key_case(),
            new array_count_values(),
            new array_unique(),
            new array_diff(),
            new array_intersect(),
            new iterator_to_array(),
            new array_replace(),
            new array_fill(),
            new array_fill_keys(),
            new array_pad(),
            new array_combine(),
            new array_map(),
            new array_filter(),
            new array_walk(),
            new array_reduce(),
            new range(),
            new bin2hex(),
            new crc32(),
            new hex2bin(),
            new base64_encode(),
            new base64_decode(),
            new hash_(),
            new hash_hmac(),
            new hash_equals(),
            new md5(),
            new sha1(),
            new crc32(),
            new password_hash(),
            new password_verify(),
            new random_bytes(),
            new random_int(),
            new uniqid(),
            new str_pad(),
            new str_split(),
            new chunk_split(),
            new wordwrap(),
            new htmlspecialchars(),
            new htmlspecialchars_decode(),
            new htmlentities(),
            new html_entity_decode(),
            new strip_tags(),
            new header_(),
            new setcookie(),
            new setrawcookie(),
            new session_start(),
            new session_id_(),
            new session_name(),
            new session_destroy(),
            new session_write_close(),
            new session_regenerate_id(),
            new header_remove(),
            new header_list(),
            new getallheaders_(),
            new ob_start(),
            new ob_get_clean(),
            new ob_end_flush(),
            new ob_get_level(),
            new http_response_code(),
            new json_encode(),
            new json_decode(),
            new serialize(),
            new unserialize(),
            new json_last_error_(),
            new web_int(),
            new web_string(),
            new web_bool(),
            new filter_var(),
            new filter_input(),
            new urlencode(),
            new rawurlencode(),
            new http_build_query(),
            new parse_str(),
            new urldecode(),
            new rawurldecode(),
            new parse_url(),
            new dirname(),
            new basename(),
            new realpath(),
            new pathinfo(),
            new file_get_contents(),
            new readfile(),
            new file_put_contents(),
            new file_exists(),
            new filesize(),
            new filemtime(),
            new clearstatcache_(),
            new stat_(),
            new lstat_(),
            new fileperms(),
            new is_file(),
            new is_dir(),
            new is_readable(),
            new is_writable(),
            new is_executable(),
            new is_link(),
            new readlink(),
            new unlink(),
            new mkdir_(),
            new rmdir_(),
            new chmod_(),
            new rename_(),
            new move_uploaded_file(),
            new is_uploaded_file(),
            new copy_(),
            new move_uploaded_file(),
            new touch_(),
            new filetype(),
            new stream_context_create(),
            new fopen(),
            new fread(),
            new fgetc(),
            new fgets(),
            new fgetcsv(),
            new fputcsv(),
            new str_getcsv(),
            new ftell_(),
            new fseek(),
            new feof_(),
            new fflush_(),
            new fpassthru(),
            new fwrite(),
            new fclose(),
            new getenv_(),
            new putenv_(),
            new shell_exec(),
            new escapeshellarg(),
            new phpc_run_command(),
            new sys_get_temp_dir(),
            new tempnam(),
            new getcwd_(),
            new chdir_(),
            new putenv_(),
            new ini_set_(),
            new ini_get_(),
            new define_(),
            new defined_(),
            new debug_backtrace(),
            new class_exists_(),
            new enum_exists_(),
            new interface_exists_(),
            new trait_exists_(),
            new class_uses_(),
            new function_exists(),
            new func_get_args(),
            new func_num_args(),
            new method_exists_(),
            new property_exists_(),
            new get_object_vars_(),
            new get_class_(),
            new get_parent_class_(),
            new is_a_(),
            new is_subclass_of_(),
            new trigger_error_(),
            new set_error_handler_(),
            new restore_error_handler_(),
            new phpc_deploy_path(),
            new compiler_is_superglobal_name(),
            new extract_(),
            new compact_(),
            new scandir(),
            new glob_(),
            new time(),
            new getmypid(),
            new microtime(),
            new date(),
            new gmdate(),
            new sleep(),
            new spl_autoload_register(),
            new usleep(),
        ];
    }

    public function jitInit(JIT\Context $context): void
    {
        try {
            $context->lookupFunction('strcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcmp', $ft);
            $context->registerFunction('strcmp', $fn);
        }
        try {
            $context->lookupFunction('memcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('memcmp', $ft);
            $context->registerFunction('memcmp', $fn);
        }
        try {
            $context->lookupFunction('strncmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('strncmp', $ft);
            $context->registerFunction('strncmp', $fn);
        }
        try {
            $context->lookupFunction('strcasecmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcasecmp', $ft);
            $context->registerFunction('strcasecmp', $fn);
        }
        try {
            $context->lookupFunction('strncasecmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('strncasecmp', $ft);
            $context->registerFunction('strncasecmp', $fn);
        }
        try {
            $context->lookupFunction('substr_compare');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i64 = $context->getTypeFromString('int64');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i64, $i64, $i32);
            $fn = $context->module->addFunction('substr_compare', $ft);
            $context->registerFunction('substr_compare', $fn);
        }
        foreach (['strspn', 'strcspn'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $i8p = $context->getTypeFromString('int8*');
                $sizeT = $context->getTypeFromString('size_t');
                $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
        try {
            $context->lookupFunction('strpbrk');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strpbrk', $ft);
            $context->registerFunction('strpbrk', $fn);
        }
        try {
            $context->lookupFunction('strstr');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strstr', $ft);
            $context->registerFunction('strstr', $fn);
        }
        try {
            $context->lookupFunction('strcasestr');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcasestr', $ft);
            $context->registerFunction('strcasestr', $fn);
        }
        try {
            $context->lookupFunction('strrchr');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i8p, false, $i8p, $i32);
            $fn = $context->module->addFunction('strrchr', $ft);
            $context->registerFunction('strrchr', $fn);
        }
        try {
            $context->lookupFunction('strtol');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i8pp = $context->getTypeFromString('int8**');
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $ft = $context->context->functionType($i64, false, $i8p, $i8pp, $i32);
            $fn = $context->module->addFunction('strtol', $ft);
            $context->registerFunction('strtol', $fn);
        }
        try {
            $context->lookupFunction('strtod');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i8pp = $context->getTypeFromString('int8**');
            $double = $context->getTypeFromString('double');
            $ft = $context->context->functionType($double, false, $i8p, $i8pp);
            $fn = $context->module->addFunction('strtod', $ft);
            $context->registerFunction('strtod', $fn);
        }
        try {
            $context->lookupFunction('strlen');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $ft = $context->context->functionType($sizeT, false, $i8p);
            $fn = $context->module->addFunction('strlen', $ft);
            $context->registerFunction('strlen', $fn);
        }
        try {
            $context->lookupFunction('realpath');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('realpath', $ft);
            $context->registerFunction('realpath', $fn);
        }
        try {
            $context->lookupFunction('stat');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('stat', $ft);
            $context->registerFunction('stat', $fn);
        }
        try {
            $context->lookupFunction('access');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i32);
            $fn = $context->module->addFunction('access', $ft);
            $context->registerFunction('access', $fn);
        }
        try {
            $context->lookupFunction('lstat');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('lstat', $ft);
            $context->registerFunction('lstat', $fn);
        }
        try {
            $context->lookupFunction('readlink');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i64 = $context->getTypeFromString('int64');
            $ft = $context->context->functionType($i64, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('readlink', $ft);
            $context->registerFunction('readlink', $fn);
        }
        try {
            $context->lookupFunction('unlink');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p);
            $fn = $context->module->addFunction('unlink', $ft);
            $context->registerFunction('unlink', $fn);
        }
        try {
            $context->lookupFunction('mkdir');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i32);
            $fn = $context->module->addFunction('mkdir', $ft);
            $context->registerFunction('mkdir', $fn);
        }
        try {
            $context->lookupFunction('rmdir');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p);
            $fn = $context->module->addFunction('rmdir', $ft);
            $context->registerFunction('rmdir', $fn);
        }
        try {
            $context->lookupFunction('chmod');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i32);
            $fn = $context->module->addFunction('chmod', $ft);
            $context->registerFunction('chmod', $fn);
        }
        try {
            $context->lookupFunction('rename');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('rename', $ft);
            $context->registerFunction('rename', $fn);
        }
        try {
            $context->lookupFunction('chdir');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p);
            $fn = $context->module->addFunction('chdir', $ft);
            $context->registerFunction('chdir', $fn);
        }
        $double = $context->getTypeFromString('double');
        try {
            $context->lookupFunction('fabs');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($double, false, $double);
            $fn = $context->module->addFunction('fabs', $ft);
            $context->registerFunction('fabs', $fn);
        }
        foreach (['ceil', 'floor', 'round', 'sqrt', 'log', 'exp', 'sin', 'cos', 'tan', 'pow', 'hypot', 'atan2', 'fmod'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $params = in_array($name, ['pow', 'hypot', 'atan2', 'fmod'], true) ? [$double, $double] : [$double];
                $ft = $context->context->functionType($double, false, ...$params);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
        $i32 = $context->getTypeFromString('int32');
        foreach (['isnan', 'isfinite', 'isinf'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($i32, false, $double);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
    }
}
