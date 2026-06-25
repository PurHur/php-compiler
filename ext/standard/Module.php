<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

class Module extends ModuleAbstract
{
    public function getAdditionalExtensionNames(): array
    {
        return ['json', 'date', 'pcre', 'zlib'];
    }

    /**
     * php-src bundled extension versions (ext/pcre/php_pcre.c PCRE2_CONFIG_VERSION, etc.).
     *
     * @return array<string, string>
     */
    public function getAdditionalExtensionVersions(): array
    {
        return [
            'pcre' => '10.44',
        ];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        \PHPCompiler\VM\OutputBufferHandlers::register(
            static fn (string $content, string $handlerName, ?\PHPCompiler\VM\Context $ctx): string => VmObOutput::processHandler(
                $ctx ?? $runtime->vmContext,
                $handlerName,
                $content
            )
        );
        BuiltinAttributes::register($runtime->vmContext);
        BuiltinEnums::register($runtime->vmContext);
        BuiltinClasses::register($runtime->vmContext);
        foreach ([
            'LOCK_SH' => 1,
            'LOCK_EX' => 2,
            'LOCK_UN' => 3,
            'LOCK_NB' => 4,
            'DEBUG_BACKTRACE_PROVIDE_OBJECT' => VmDebugBacktrace::PROVIDE_OBJECT,
            'DEBUG_BACKTRACE_IGNORE_ARGS' => VmDebugBacktrace::IGNORE_ARGS,
            'DEBUG_BACKTRACE_IGNORE_STATIC_ARGS' => VmDebugBacktrace::IGNORE_STATIC_ARGS,
            'CONNECTION_NORMAL' => VmConnection::NORMAL,
            'CONNECTION_ABORTED' => VmConnection::ABORTED,
            'CONNECTION_TIMEOUT' => VmConnection::TIMEOUT,
            'INFO_GENERAL' => VmInfo::INFO_GENERAL,
            'INFO_CREDITS' => VmInfo::INFO_CREDITS,
            'INFO_CONFIGURATION' => VmInfo::INFO_CONFIGURATION,
            'INFO_MODULES' => VmInfo::INFO_MODULES,
            'INFO_ENVIRONMENT' => VmInfo::INFO_ENVIRONMENT,
            'INFO_VARIABLES' => VmInfo::INFO_VARIABLES,
            'INFO_LICENSE' => VmInfo::INFO_LICENSE,
            'INFO_ALL' => VmInfo::INFO_ALL,
            'CREDITS_GROUP' => VmInfo::CREDITS_GROUP,
            'CREDITS_GENERAL' => VmInfo::CREDITS_GENERAL,
            'CREDITS_SAPI' => VmInfo::CREDITS_SAPI,
            'CREDITS_MODULES' => VmInfo::CREDITS_MODULES,
            'CREDITS_DOCS' => VmInfo::CREDITS_DOCS,
            'CREDITS_FULLPAGE' => VmInfo::CREDITS_FULLPAGE,
            'CREDITS_QA' => VmInfo::CREDITS_QA,
            'CREDITS_ALL' => VmInfo::CREDITS_ALL,
            'PHP_QUERY_RFC1738' => VmHttpBuildQuery::ENCODING_RFC1738,
            'PHP_QUERY_RFC3986' => VmHttpBuildQuery::ENCODING_RFC3986,
            'PHP_URL_SCHEME' => VmParseUrl::PHP_URL_SCHEME,
            'PHP_URL_HOST' => VmParseUrl::PHP_URL_HOST,
            'PHP_URL_PORT' => VmParseUrl::PHP_URL_PORT,
            'PHP_URL_USER' => VmParseUrl::PHP_URL_USER,
            'PHP_URL_PASS' => VmParseUrl::PHP_URL_PASS,
            'PHP_URL_PATH' => VmParseUrl::PHP_URL_PATH,
            'PHP_URL_QUERY' => VmParseUrl::PHP_URL_QUERY,
            'PHP_URL_FRAGMENT' => VmParseUrl::PHP_URL_FRAGMENT,
            'SUNFUNCS_RET_STRING' => VmDate::SUNFUNCS_RET_STRING,
            'SUNFUNCS_RET_DOUBLE' => VmDate::SUNFUNCS_RET_DOUBLE,
            'SUNFUNCS_RET_TIMESTAMP' => VmDate::SUNFUNCS_RET_TIMESTAMP,
            'LOG_EMERG' => StdlibConstants::LOG_EMERG,
            'LOG_ALERT' => StdlibConstants::LOG_ALERT,
            'LOG_CRIT' => StdlibConstants::LOG_CRIT,
            'LOG_ERR' => StdlibConstants::LOG_ERR,
            'LOG_WARNING' => StdlibConstants::LOG_WARNING,
            'LOG_NOTICE' => StdlibConstants::LOG_NOTICE,
            'LOG_INFO' => StdlibConstants::LOG_INFO,
            'LOG_DEBUG' => StdlibConstants::LOG_DEBUG,
            'LOG_PID' => StdlibConstants::LOG_PID,
            'LOG_CONS' => StdlibConstants::LOG_CONS,
            'LOG_ODELAY' => StdlibConstants::LOG_ODELAY,
            'LOG_NDELAY' => StdlibConstants::LOG_NDELAY,
            'LOG_NOWAIT' => StdlibConstants::LOG_NOWAIT,
            'LOG_PERROR' => StdlibConstants::LOG_PERROR,
            'LOG_KERN' => StdlibConstants::LOG_KERN,
            'LOG_USER' => StdlibConstants::LOG_USER,
            'LOG_MAIL' => StdlibConstants::LOG_MAIL,
            'LOG_DAEMON' => StdlibConstants::LOG_DAEMON,
            'LOG_AUTH' => StdlibConstants::LOG_AUTH,
            'LOG_SYSLOG' => StdlibConstants::LOG_SYSLOG,
            'LOG_LPR' => StdlibConstants::LOG_LPR,
            'LOG_NEWS' => StdlibConstants::LOG_NEWS,
            'LOG_UUCP' => StdlibConstants::LOG_UUCP,
            'LOG_CRON' => StdlibConstants::LOG_CRON,
            'LOG_AUTHPRIV' => StdlibConstants::LOG_AUTHPRIV,
            'LOG_FTP' => StdlibConstants::LOG_FTP,
            'LOG_LOCAL0' => StdlibConstants::LOG_LOCAL0,
            'LOG_LOCAL1' => StdlibConstants::LOG_LOCAL1,
            'LOG_LOCAL2' => StdlibConstants::LOG_LOCAL2,
            'LOG_LOCAL3' => StdlibConstants::LOG_LOCAL3,
            'LOG_LOCAL4' => StdlibConstants::LOG_LOCAL4,
            'LOG_LOCAL5' => StdlibConstants::LOG_LOCAL5,
            'LOG_LOCAL6' => StdlibConstants::LOG_LOCAL6,
            'LOG_LOCAL7' => StdlibConstants::LOG_LOCAL7,
            ...VmLocale::lcConstants(),
            ...VmLocale::nlLanginfoConstants(),
            'ZLIB_ENCODING_RAW' => -15,
            'ZLIB_ENCODING_DEFLATE' => 15,
            'ZLIB_ENCODING_GZIP' => 31,
            'STREAM_PF_UNIX' => StdlibConstants::STREAM_PF_UNIX,
            'STREAM_PF_INET' => StdlibConstants::STREAM_PF_INET,
            'STREAM_SOCK_STREAM' => StdlibConstants::STREAM_SOCK_STREAM,
            'STREAM_SOCK_DGRAM' => StdlibConstants::STREAM_SOCK_DGRAM,
            'STREAM_IPPROTO_IP' => StdlibConstants::STREAM_IPPROTO_IP,
        ] + VmStreamSupports::constants() + VmStreamNotification::constants() + VmImage::constants() + VmJsonFlags::constants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

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
            new log10(),
            new exp(),
            new expm1(),
            new log1p(),
            new sin(),
            new cos(),
            new tan(),
            new acos(),
            new asin(),
            new atan(),
            new sinh(),
            new cosh(),
            new tanh(),
            new acosh(),
            new asinh(),
            new atanh(),
            new is_nan(),
            new is_finite(),
            new is_infinite(),
            new pow(),
            new hypot(),
            new atan2(),
            new fmod(),
            new modf(),
            new ldexp(),
            new frexp(),
            new fdiv(),
            ...(CompilerVersion::supportsFpow() ? [new fpow(), new fmin(), new fmax()] : []),
            new intval(),
            new floatval(),
            new doubleval(),
            new boolval(),
            new settype(),
            new var_export(),
            new var_dump_(),
            new debug_zval_dump(),
            new print_r(),
            new gettype(),
            new get_debug_type(),
            new gc_collect_cycles(),
            new gc_enable(),
            new gc_disable(),
            new gc_enabled(),
            new gc_status(),
            new gc_mem_caches(),
            new halt_compiler_(),
            new exit_(),
            new die_(),
            new strval(),
            new int_min(),
            new int_max(),
            new intdiv(),
            new ord(),
            new pack(),
            new unpack(),
            new chr(),
            new strcmp(),
            new strcoll(),
            new strxfrm(),
            new levenshtein(),
            new similar_text(),
            new soundex(),
            new metaphone(),
            new hebrev(),
            new convert_cyr_string(),
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
            new base_convert_(),
            new is_numeric(),
            new is_scalar(),
            new is_countable(),
            new is_iterable(),
            new is_resource_(),
            new get_resource_type(),
            new get_resource_id(),
            new get_resources_(),
            new lcfirst(),
            new ucfirst(),
            new ucwords(),
            new strtolower(),
            new strtoupper(),
            new string_trim(),
            new string_ltrim(),
            new string_rtrim(),
            new string_rtrim('chop'),
            new substr(),
            new substr_replace(),
            new strrev(),
            new str_rot13(),
            ...(CompilerVersion::supportsStrIncrement() ? [new str_increment(), new str_decrement()] : []),
            new str_shuffle(),
            new strpos(),
            new strstr(),
            new strtok(),
            new strchr(),
            new stristr(),
            new strrchr(),
            new stripos(),
            new strrpos(),
            new strripos(),
            new substr_count(),
            new count_chars(),
            new convert_uudecode(),
            new convert_uuencode(),
            new utf8_decode(),
            new utf8_encode(),
            new str_word_count(),
            new str_contains(),
            new str_starts_with(),
            new str_ends_with(),
            new strncmp(),
            new memcmp(),
            new substr_compare(),
            new array_count(),
            new array_count('sizeof'),
            new array_key_exists(),
            new array_key_exists('key_exists'),
            new array_key_first(),
            new array_key_last(),
            new key(),
            new current(),
            new pos(),
            new next(),
            new prev(),
            new reset_(),
            new end_(),
            new array_first(),
            new array_last(),
            new array_is_list(),
            new array_is_assoc(),
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
            new array_uasort(),
            new uksort_(),
            new array_uksort(),
            new sprintf_(),
            new printf_(),
            new vprintf_(),
            new vfprintf_(),
            new fprintf_(),
            new vsprintf(),
            new sscanf(),
            new vfscanf(),
            new fscanf(),
            new array_values(),
            new array_keys(),
            new array_merge(),
            new array_merge_recursive(),
            new array_slice(),
            new array_splice(),
            new array_chunk(),
            new array_column(),
            new explode(),
            new implode(),
            new implode('join'),
            new image_type_to_extension(),
            new image_type_to_mime_type(),
            new getimagesize(),
            new getimagesizefromstring(),
            new iptcembed(),
            new iptcparse(),
            new str_replace(),
            new str_ireplace(),
            new strtr(),
            new preg_quote(),
            new quotemeta(),
            new addslashes(),
            new addcslashes(),
            new stripslashes(),
            new stripcslashes(),
            new preg_match(),
            new preg_match_all(),
            new preg_grep(),
            new preg_filter(),
            new preg_replace(),
            new preg_replace_callback(),
            new preg_replace_callback_array(),
            new preg_split(),
            new preg_last_error_(),
            new preg_last_error_msg_(),
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
            new array_diff_assoc(),
            new array_diff_key(),
            new array_diff_uassoc(),
            new array_diff_ukey(),
            new array_intersect_ukey(),
            new array_intersect(),
            new array_intersect_assoc(),
            new array_intersect_uassoc(),
            new array_intersect_key(),
            new array_udiff(),
            new array_udiff_assoc(),
            new array_udiff_uassoc(),
            new array_uintersect(),
            new array_uintersect_assoc(),
            new array_uintersect_uassoc(),
            new iterator_to_array(),
            new generator_to_array(),
            new iterator_count(),
            new iterator_apply(),
            new array_replace(),
            new array_replace_recursive(),
            new array_replace_key(),
            new array_fill(),
            new array_fill_keys(),
            new array_pad(),
            new array_combine(),
            new array_map(),
            new array_filter(),
            new array_find(),
            new array_find_key(),
            new array_any(),
            new array_all(),
            new array_walk(),
            new array_walk_recursive(),
            new array_reduce(),
            new range(),
            new bin2hex(),
            new crc32(),
            new crc32c(),
            new hex2bin(),
            new base64_encode(),
            new base64_decode(),
            new quoted_printable_encode(),
            new quoted_printable_decode(),
            new hash_(),
            new hash_hmac(),
            new hash_hmac_algos(),
            new hash_pbkdf2(),
            new hash_hkdf(),
            new hash_equals(),
            new md5(),
            new md5_file(),
            new sha1(),
            new sha1_file(),
            new crc32(),
            new password_hash(),
            new password_verify(),
            new password_get_info(),
            new password_needs_rehash(),
            new password_algos(),
            new crypt(),
            new random_bytes(),
            new openssl_random_pseudo_bytes(),
            new random_int(),
            new uniqid(),
            new str_pad(),
            ...(CompilerVersion::supportsStrPadded() ? [new str_padded()] : []),
            new str_split(),
            new chunk_split(),
            new wordwrap(),
            new htmlspecialchars(),
            new htmlspecialchars_decode(),
            new highlight_string(),
            new highlight_file(),
            new show_source(),
            new php_strip_whitespace(),
            new htmlentities(),
            new html_entity_decode(),
            new get_html_translation_table(),
            new get_meta_tags(),
            new get_browser(),
            new strip_tags(),
            new header_(),
            new headers_sent(),
            new connection_status(),
            new header_register_callback(),
            new register_shutdown_function(),
            new readonly_(),
            new setcookie(),
            new setrawcookie(),
            new header_remove(),
            new header_list(),
            new headers_list(),
            new getallheaders_(),
            new getallheaders_('apache_request_headers'),
            new http_get_last_response_headers(),
            new get_last_response_headers(),
            new http_clear_last_response_headers(),
            new get_headers(),
            new ob_start(),
            new ob_gzhandler(),
            new ob_get_clean(),
            new ob_get_contents(),
            new ob_get_flush(),
            new ob_end_clean(),
            new ob_get_length(),
            new ob_end_flush(),
            new flush_(),
            new ob_get_level(),
            new ob_get_status(),
            new ob_implicit_flush(),
            new ob_flush(),
            new ob_clean(),
            new ob_list_handlers(),
            new http_response_code(),
            new output_add_rewrite_var(),
            new output_reset_rewrite_vars(),
            new json_encode(),
            new json_decode(),
            new json_validate(),
            new serialize(),
            new unserialize(),
            new json_last_error_(),
            new json_last_error_msg_(),
            new web_int(),
            new web_string(),
            new web_bool(),
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
            new realpath_cache_get(),
            new realpath_cache_size(),
            new pathinfo(),
            new file_get_contents(),
            new readfile(),
            new mime_content_type(),
            new file_(),
            new readline(),
            new readline_info(),
            new readline_add_history(),
            new readline_clear_history(),
            new readline_list_history(),
            new readline_read_history(),
            new readline_write_history(),
            new readline_completion_function(),
            new readline_callback_handler_install(),
            new readline_callback_read_char(),
            new readline_callback_handler_remove(),
            new readline_on_new_line(),
            new readline_redisplay(),
            new file_put_contents(),
            new file_exists(),
            new filesize(),
            new filemtime(),
            new disk_free_space(),
            new disk_total_space(),
            new diskfreespace(),
            new disktotalspace(),
            new dl(),
            new fileatime(),
            new filectime(),
            new fileinode(),
            new fileowner(),
            new filegroup(),
            new clearstatcache_(),
            new stat_(),
            new lstat_(),
            new fstat_(),
            new fileperms(),
            new is_file(),
            new is_dir(),
            new is_readable(),
            new is_writable(),
            new is_executable(),
            new is_link(),
            new readlink(),
            new linkinfo(),
            new link_(),
            new symlink_(),
            new unlink(),
            new mkdir_(),
            new rmdir_(),
            new chmod_(),
            new chown_(),
            new lchown_(),
            new chgrp_(),
            new lchgrp_(),
            new umask_(),
            new rename_(),
            new move_uploaded_file(),
            new is_uploaded_file(),
            new copy_(),
            new move_uploaded_file(),
            new touch_(),
            new filetype(),
            new stream_context_create(),
            new stream_context_get_default(),
            new stream_context_set_default(),
            new stream_context_get_options(),
            new stream_context_set_options(),
            new stream_context_set_params(),
            new stream_notification_callback(),
            new stream_socket_client(),
            new stream_socket_server(),
            new stream_socket_pair(),
            new fsockopen(),
            new pfsockopen(),
            new stream_set_chunk_size_(),
            new stream_set_timeout_(),
            new stream_set_write_buffer_(),
            new stream_set_read_buffer_(),
            new set_file_buffer(),
            new stream_supports(),
            new stream_supports_lock(),
            new stream_is_local(),
            new stream_isatty(),
            new stream_get_meta_data(),
            new stream_set_blocking(),
            new fopen(),
            new fread(),
            new stream_get_contents(),
            new stream_copy_to_stream(),
            new stream_copy_to_string(),
            new stream_get_filters(),
            new stream_filter_register(),
            new stream_filter_append(),
            new stream_filter_prepend(),
            new stream_filter_remove(),
            new stream_get_wrappers(),
            new stream_get_transports(),
            new stream_wrapper_register(),
            new stream_register_wrapper(),
            new stream_wrapper_unregister(),
            new stream_wrapper_restore(),
            new stream_bucket_new(),
            new stream_bucket_make_writeable(),
            new stream_bucket_append(),
            new stream_bucket_prepend(),
            new fgetc(),
            new fgets(),
            new stream_get_line(),
            new fgetcsv(),
            new fputcsv(),
            new str_getcsv(),
            new ftell_(),
            new ftok(),
            new fseek(),
            new rewind_(),
            new feof_(),
            new fflush_(),
            new fsync_(),
            new fdatasync_(),
            new ftruncate_(),
            new fpassthru(),
            new fwrite(),
            new fwrite('fputs'),
            new fclose(),
            new flock(),
            new forward_static_call(),
            new forward_static_call_array(),
            new getenv_(),
            new putenv_(),
            new exec(),
            new passthru(),
            new system(),
            new shell_exec(),
            new popen(),
            new pclose(),
            new proc_open(),
            new proc_close(),
            new proc_get_status(),
            new proc_terminate(),
            new stream_select(),
            new escapeshellarg(),
            new escapeshellcmd(),
            new phpc_run_command(),
            new sys_get_temp_dir(),
            new sys_getloadavg(),
            new openlog(),
            new syslog(),
            new closelog(),
            new tempnam(),
            new tmpfile(),
            new getcwd_(),
            new get_include_path(),
            new set_include_path(),
            new restore_include_path(),
            new stream_resolve_include_path(),
            new gethostname(),
            new net_get_interfaces(),
            new gethostbynamel(),
            new gethostbyname(),
            new gethostbyaddr(),
            new checkdnsrr(),
            new checkdnsrr('dns_check_record'),
            new dns_get_mx(),
            new dns_get_record(),
            new getmxrr(),
            new long2ip(),
            new ip2long(),
            new inet_ntop(),
            new inet_pton(),
            new getprotobyname(),
            new getprotobynumber(),
            new getservbyname(),
            new getservbyport(),
            new chdir_(),
            new chroot_(),
            new putenv_(),
            new ini_set_(),
            new ini_set_('ini_alter'),
            new ini_get_(),
            new ini_get_all(),
            new ini_restore(),
            new ini_parse_quantity(),
            new parse_ini_string(),
            new parse_ini_file(),
            new error_reporting(),
            new error_log(),
            new define_(),
            new defined_(),
            new constant_(),
            new class_constants_(),
            new get_defined_constants_(),
            new get_defined_vars_(),
            new get_declared_variables_(),
            new get_declared_interfaces_(),
            new get_declared_classes_(),
            new get_declared_traits_(),
            new get_declared_attributes_(),
            new get_declared_functions_(),
            new get_defined_functions_(),
            new get_included_files_(),
            new get_included_files_('get_required_files'),
            new debug_backtrace(),
            new debug_print_backtrace(),
            new get_debug_backtrace(),
            new class_exists_(),
            new class_alias(),
            new create_lazy_ghost(),
            new create_lazy_proxy(),
            new enum_exists_(),
            new unitenum_exists_(),
            new interface_exists_(),
            new trait_exists_(),
            new class_uses_(),
            new class_uses_recursive(),
            new class_implements_(),
            new class_parents_(),
            new function_exists(),
            new is_callable(),
            new call_user_func(),
            new call_user_func_array(),
            new func_get_arg(),
            new func_get_args(),
            new func_num_args(),
            new method_exists_(),
            new class_meth_exists_(),
            ...(CompilerVersion::supportsClassHasFunctions() ? [
                new class_has_method_(),
                new class_has_property_(),
                new class_has_constant_(),
            ] : []),
            new property_exists_(),
            new attribute_exists_(),
            new get_object_vars_(),
            new get_mangled_object_vars_(),
            new get_object_id(),
            new spl_object_id(),
            new spl_object_hash(),
            new get_class_(),
            new get_called_class_(),
            new get_class_vars_(),
            new get_class_methods_(),
            new get_parent_class_(),
            new is_a_(),
            new is_subclass_of_(),
            new assert_(),
            new assert_options(),
            new trigger_error_(),
            new user_error(),
            new compiler_language_warning_(),
            new set_error_handler_(),
            new restore_error_handler_(),
            new set_exception_handler(),
            new restore_exception_handler(),
            new error_get_last(),
            new error_clear_last(),
            new exif_tagname(),
            new eval_(),
            new phpc_deploy_path(),
            new compiler_is_superglobal_name(),
            new phpc_match_unhandled_operand_is_object(),
            new phpc_clone_with_begin(),
            new phpc_clone_with_end(),
            new phpc_clone_with_reinit(),
            new extract_(),
            new compact_(),
            new scandir(),
            new opendir(),
            new readdir(),
            new closedir(),
            new rewinddir(),
            new glob_(),
            new gzcompress(),
            new gzdecode(),
            new gzdeflate(),
            new gzencode(),
            new gzinflate(),
            new gzuncompress(),
            new gzopen(),
            new gzwrite(),
            new gzread(),
            new gzgets(),
            new gzclose(),
            new readgzfile(),
            new gzfile(),
            new gzpassthru(),
            new zlib_encode(),
            new zlib_decode(),
            new fnmatch(),
            new time(),
            new getmypid(),
            new getmyuid(),
            new getmygid(),
            new get_current_user(),
            new get_cfg_var(),
            new php_ini_loaded_file(),
            new php_ini_scanned_files(),
            new zend_thread_id(),
            new getmygrgid(),
            new getmyinode(),
            new getlastmod(),
            new getrusage(),
            new cli_get_process_title(),
            new cli_set_process_title(),
            new proc_nice(),
            new memory_get_peak_usage(),
            new memory_get_usage(),
            new memory_reset_peak_usage(),
            new microtime(),
            new gettimeofday(),
            new hrtime(),
            new clock_gettime(),
            new phpversion(),
            new php_sapi_name(),
            new getopt(),
            new php_uname(),
            new phpinfo(),
            new phpcredits(),
            new zend_version(),
            new version_compare(),
            new extension_loaded(),
            new get_loaded_extensions(),
            new get_extension_funcs(),
            new date(),
            new timezone_version_get(),
            new timezone_identifiers_list(),
            new timezone_open(),
            new timezone_offset_get(),
            new timezone_location_get(),
            new timezone_transitions_get(),
            new gmdate(),
            new strftime(),
            new gmstrftime(),
            new strptime(),
            new getdate(),
            new gmgetdate(),
            new gmmktime(),
            new mktime(),
            new strtotime(),
            new checkdate(),
            new date_default_timezone_get(),
            new date_default_timezone_set(),
            new localtime(),
            new localeconv(),
            new idate(),
            new date_sun_info(),
            new date_interval_format(),
            new date_interval_create_from_date_string(),
            new date_create(),
            new date_create_immutable(),
            new date_create_from_format(),
            new date_create_immutable_from_format(),
            new date_parse(),
            new date_parse_from_format(),
            new date_add(),
            new date_sub(),
            new date_modify(),
            new date_diff(),
            new date_sunrise(),
            new date_sunset(),
            new sleep(),
            new set_time_limit(),
            new setlocale(),
            new nl_langinfo(),
            new ignore_user_abort(),
            new connection_aborted(),
            new spl_autoload(),
            new spl_autoload_extensions(),
            new spl_autoload_functions(),
            new spl_autoload_register(),
            new spl_autoload_unregister(),
            new spl_autoload_call(),
            new time_nanosleep(),
            new time_sleep_until(),
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
            $context->lookupFunction('strcoll');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcoll', $ft);
            $context->registerFunction('strcoll', $fn);
        }
        try {
            $context->lookupFunction('nl_langinfo');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i8p, false, $i32);
            $fn = $context->module->addFunction('nl_langinfo', $ft);
            $context->registerFunction('nl_langinfo', $fn);
        }
        try {
            $context->lookupFunction('strxfrm');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $ft = $context->context->functionType($sizeT, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('strxfrm', $ft);
            $context->registerFunction('strxfrm', $fn);
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
            $context->lookupFunction('phpc_basetozval_result');
        } catch (\Throwable $e) {
            $charPtr = $context->getTypeFromString('char*');
            $i64 = $context->getTypeFromString('int64');
            $i64Ptr = $context->getTypeFromString('int64*');
            $doublePtr = $context->getTypeFromString('double*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $charPtr, $i64, $i64Ptr, $doublePtr);
            $fn = $context->module->addFunction('phpc_basetozval_result', $ft);
            $context->registerFunction('phpc_basetozval_result', $fn);
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
            $context->lookupFunction('statvfs');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('statvfs', $ft);
            $context->registerFunction('statvfs', $fn);
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
            $context->lookupFunction('umask');
        } catch (\Throwable $e) {
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction('umask', $ft);
            $context->registerFunction('umask', $fn);
        }
        try {
            $context->lookupFunction('nice');
        } catch (\Throwable $e) {
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction('nice', $ft);
            $context->registerFunction('nice', $fn);
        }
        try {
            $context->lookupFunction('__errno_location');
        } catch (\Throwable $e) {
            $i32 = $context->getTypeFromString('int32');
            $i32Ptr = $i32->pointerType(0);
            $ft = $context->context->functionType($i32Ptr, false);
            $fn = $context->module->addFunction('__errno_location', $ft);
            $context->registerFunction('__errno_location', $fn);
        }
        try {
            $context->lookupFunction('fnmatch');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i32);
            $fn = $context->module->addFunction('fnmatch', $ft);
            $context->registerFunction('fnmatch', $fn);
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
            $context->lookupFunction('linkat');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i32, $i8p, $i32, $i8p, $i32);
            $fn = $context->module->addFunction('linkat', $ft);
            $context->registerFunction('linkat', $fn);
        }
        try {
            $context->lookupFunction('symlinkat');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i32, $i8p);
            $fn = $context->module->addFunction('symlinkat', $ft);
            $context->registerFunction('symlinkat', $fn);
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
        try {
            $context->lookupFunction('chroot');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p);
            $fn = $context->module->addFunction('chroot', $ft);
            $context->registerFunction('chroot', $fn);
        }
        try {
            $context->lookupFunction('gethostname');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $sizeT = $context->getTypeFromString('size_t');
            $ft = $context->context->functionType($i32, false, $i8p, $sizeT);
            $fn = $context->module->addFunction('gethostname', $ft);
            $context->registerFunction('gethostname', $fn);
        }
        $double = $context->getTypeFromString('double');
        try {
            $context->lookupFunction('fabs');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($double, false, $double);
            $fn = $context->module->addFunction('fabs', $ft);
            $context->registerFunction('fabs', $fn);
        }
        foreach (['ceil', 'floor', 'round', 'sqrt', 'log', 'log10', 'exp', 'expm1', 'log1p', 'sin', 'cos', 'tan', 'acos', 'asin', 'atan', 'sinh', 'cosh', 'tanh', 'acosh', 'asinh', 'atanh', 'pow', 'hypot', 'atan2', 'fmod'] as $name) {
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
        $doublePtr = $context->getTypeFromString('double*');
        $i32Ptr = $context->getTypeFromString('int32*');
        foreach ([
            'ldexp' => [$double, $i32],
            'modf' => [$double, $doublePtr],
            'frexp' => [$double, $i32Ptr],
        ] as $name => $params) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($double, false, ...$params);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
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
