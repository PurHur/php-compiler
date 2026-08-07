<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * By-reference parameter indices for VM builtins (issue #3578).
 *
 * @return list<int>
 */
final class BuiltinByRefParams
{
    public static function forFunction(string $name): array
    {
        switch (strtolower($name)) {
            case 'array_multisort':
            case 'array_push':
            case 'array_pop':
            case 'array_shift':
            case 'array_unshift':
            case 'array_splice':
            case 'end':
            case 'next':
            case 'prev':
            case 'reset':
            case 'array_walk':
            case 'array_walk_recursive':
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
            case 'natcasesort':
            case 'natsort':
            case 'rsort':
            case 'shuffle':
            case 'sort':
            case 'uasort':
            case 'uksort':
            case 'usort':
            case 'extract':
                // php-src basic_functions.stub.php — extract(array &$array, ...) (#23572)
                return [0];
            case 'frexp':
                return [1];
            case 'parse_str':
            case 'mb_parse_str':
                return [1];
            case 'xml_parse_into_struct':
                return [2, 3];
            case 'dns_get_mx':
            case 'getmxrr':
                return [1, 2];
            // php-src basic_functions.stub.php — &$authoritative_name_servers, &$additional_records (#23358)
            case 'dns_get_record':
                return [2, 3];
            case 'stream_socket_client':
                return [1, 2];
            case 'stream_socket_accept':
                return [2];
            case 'stream_socket_server':
                return [1, 2];
            case 'socket_create_pair':
                return [3];
            case 'socket_select':
                return [0, 1, 2];
            // php-src ext/standard/basic_functions.stub.php — &$read,&$write,&$except (#23598)
            case 'stream_select':
                return [0, 1, 2];
            case 'socket_getsockname':
            case 'socket_getpeername':
                return [1, 2];
            case 'socket_recvfrom':
                return [1, 4, 5];
            case 'socket_recv':
                return [1];
            case 'socket_recvmsg':
                return [1];
            case 'fsockopen':
            case 'pfsockopen':
                return [2, 3];
            case 'settype':
                return [0];
            case 'similar_text':
                return [2];
            case 'preg_match':
            case 'preg_match_all':
                return [2];
            case 'mb_ereg':
            case 'mb_eregi':
                return [2];
            case 'preg_replace':
            case 'preg_filter':
            case 'preg_replace_callback':
                // php-src ext/pcre/php_pcre.c — &$count (#19637, #4442, #12904)
                return [4];
            case 'preg_replace_callback_array':
                // pattern, subject, limit, &$count — index 3 (#19637)
                return [3];
            case 'str_replace':
            case 'str_ireplace':
                return [3];
            case 'headers_sent':
                return [0, 1];
            case 'flock':
                return [2];
            case 'fscanf':
                // php-src basic_functions.stub.php — mixed &...$vars (#26058)
                // Reflection reads forFunction(); runtime also uses variadicByRefFromIndex(2).
                return [2];
            case 'getopt':
                return [2];
            case 'is_callable':
                return [2];
            case 'openssl_random_pseudo_bytes':
            case 'openssl_sign':
            case 'openssl_public_encrypt':
            case 'openssl_private_decrypt':
            case 'openssl_private_encrypt':
            case 'openssl_public_decrypt':
            case 'openssl_open':
            case 'openssl_pkey_export':
            case 'openssl_pkcs12_export':
            case 'openssl_csr_export':
            case 'openssl_x509_export':
                return [1];
            case 'openssl_encrypt':
                // &$tag — php-src openssl.stub.php (#21135)
                return [5];
            case 'openssl_csr_new':
                return [1];
            case 'openssl_pkcs12_read':
            case 'openssl_cms_read':
            case 'openssl_pkcs7_read':
                return [1];
            case 'openssl_seal':
                return [1, 2, 5];
            case 'stream_context_set_options':
            case 'stream_context_set_params':
                return [0];
            // php-src basic_functions.stub.php — $context not by-ref (#25845; set_options/params still &)
            case 'stream_context_set_option':
                return [];
            case 'exec':
                return [1, 2];
            case 'passthru':
            case 'system':
                return [1];
            case 'proc_open':
                return [2];
            case 'grapheme_extract':
                return [4];
            case 'idn_to_ascii':
            case 'idn_to_utf8':
                return [3];
            case 'sodium_crypto_secretstream_xchacha20poly1305_push':
            case 'sodium_crypto_secretstream_xchacha20poly1305_pull':
            case 'sodium_crypto_secretstream_xchacha20poly1305_rekey':
            case 'sodium_crypto_generichash_update':
            case 'sodium_crypto_generichash_final':
                return [0];
            case 'sodium_memzero':
            case 'sodium_increment':
                return [0];
            case 'sodium_add':
                return [0];
            case 'uuid_generate':
                return [0];
            case 'collator::asort':
            case 'collator::sort':
            case 'collator::sortwithsortkeys':
                // $this + &$array (+ optional flags) — php-src collator.stub.php (#5747, #20717)
                return [1];
            case 'uconverter::fromucallback':
            case 'uconverter::toucallback':
                // $this + $reason + $source + $codePoint|$codeUnits + &$error — php-src converter.stub.php (#20917)
                return [4];
            case 'collator_asort':
            case 'collator_sort':
            case 'collator_sort_with_sort_keys':
                // $object + &$array (+ optional flags) — php-src collator.stub.php (#20838)
                return [1];
            case 'numberformatter::parse':
                // $this + $string + optional $type + optional &$offset — php-src formatter.stub.php (#21139)
                return [3];
            case 'numfmt_parse':
                // $formatter + $string + optional $type + optional &$offset — php-src formatter.stub.php (#21139)
                return [3];
            case 'numberformatter::parsecurrency':
                // $this + $string + &$currency + optional &$offset — php-src formatter.stub.php (#20728, #21127)
                return [2, 3];
            case 'numfmt_parse_currency':
                // $formatter + $string + &$currency + optional &$offset — php-src formatter.stub.php (#20780, #21127)
                return [2, 3];
            case 'spoofchecker::issuspicious':
                // $this + $string + optional &$errorCode — php-src spoofchecker.stub.php (#25055)
                return [2];
            case 'spoofchecker::areconfusable':
                // $this + $string1 + $string2 + optional &$errorCode — php-src spoofchecker.stub.php (#25055)
                return [3];
            case 'intldateformatter::parse':
            case 'intldateformatter::parsetocalendar':
            case 'intldateformatter::localtime':
                // $this + $string + &$offset — php-src dateformat.stub.php (#20729, #22622)
                return [2];
            case 'datefmt_parse':
            case 'datefmt_localtime':
                // $formatter + $string + &$offset — php-src dateformat.stub.php (#20803)
                return [2];
            case 'intltimezone::getoffset':
                // $this + $date + $local + &$rawOffset + &$dstOffset — php-src timezone.stub.php (#20769)
                return [3, 4];
            case 'intltz_get_offset':
                // $timezone + $date + $local + &$rawOffset + &$dstOffset — php-src timezone.stub.php (#20925)
                return [3, 4];
            case 'intltimezone::getcanonicalid':
                // $timezoneId + &$isSystemId — php-src timezone.stub.php (#20769)
                return [1];
            case 'intltz_get_canonical_id':
                // $timezoneId + &$isSystemId — php-src timezone.stub.php (#20859 / #20925)
                return [1];
            case 'redis::scan':
                // $this + &$iterator (+ optional pattern/count) — phpredis redis.stub.php (#20682)
                return [1];
            case 'redis::hscan':
            case 'redis::sscan':
            case 'redis::zscan':
                // $this + $key + &$iterator (+ optional pattern/count) (#20682)
                return [2];
            case 'ziparchive::getexternalattributesname':
            case 'ziparchive::getexternalattributesindex':
                // $this + $name|$index + &$opsys + &$attr (+ optional flags) — php-src php_zip.stub.php (#20363)
                return [2, 3];
            case 'sqlite3stmt::bindparam':
                // $this + $param + &$var (+ optional $type) — php-src sqlite3.stub.php (#19854)
                return [2];
            case 'pdostatement::bindparam':
                // $this + $param + &$var (+ optional $type/$maxLength/…) — php-src pdo.stub.php (#19853)
                return [2];
            case 'pdostatement::bindcolumn':
                // $this + $column + &$var (+ optional $type/…) — php-src pdo.stub.php (#22274)
                return [2];
            case 'msg_send':
                // php-src ext/sysvmsg/sysvmsg.stub.php — &$error_code (#3666)
                return [5];
            case 'msg_receive':
                // &$received_message_type, &$message, &$error_code (#3666)
                return [2, 4, 7];
            case 'pcntl_wait':
                // php-src ext/pcntl/pcntl.stub.php — &$status (#19565)
                return [0];
            case 'pcntl_waitpid':
                // php-src ext/pcntl/pcntl.stub.php — &$status, &$resource_usage (#19564, #27849)
                return [1, 3];
            case 'pcntl_sigprocmask':
                // &$old_signals
                return [2];
            case 'pcntl_sigtimedwait':
                // &$info
                return [1];
            case 'pcntl_waitid':
                // &$info
                return [2];
            case 'ftp_alloc':
                // php-src ext/ftp/ftp.stub.php — &$result (#20060)
                return [2];
            case 'exif_thumbnail':
                // php-src ext/exif/exif.stub.php — &$width, &$height, &$image_type (#20027)
                return [1, 2, 3];
            case 'getimagesize':
            case 'getimagesizefromstring':
                // php-src ext/standard/image.stub.php — &$image_info (#23816)
                return [1];
            case 'curl_multi_exec':
                // php-src ext/curl/multi.c / curl.stub.php — &$still_running (#3721)
                return [1];
            case 'curl_multi_info_read':
                // php-src ext/curl/multi.c / curl.stub.php — &$queued_messages (#20495)
                return [1];
            case 'xmlrpc_decode_request':
                // php-src ext/xmlrpc — &$method (#22254)
                return [1];
            case 'xmlrpc_set_type':
                // php-src ext/xmlrpc — &$value (#22254)
                return [0];
            case 'enchant_dict_quick_check':
                // php-src ext/enchant/enchant.stub.php — &$suggestions (#20613)
                return [2];
            case 'apcu_fetch':
                // PECL apcu apcu.stub.php — &$success (#6574)
                return [1];
            case 'apcu_inc':
            case 'apcu_dec':
                // PECL apcu — &$success (#22253)
                return [2];
            case 'ldap_get_option':
                // php-src ext/ldap/ldap.c — &$retval (#21851)
                return [2];
        }

        return [];
    }

    /** First argument index passed by reference for variadic tail (issue #3190). */
    public static function variadicByRefFromIndex(string $name): ?int
    {
        $lc = strtolower($name);
        if (\in_array($lc, ['sscanf', 'vfscanf', 'fscanf'], true)) {
            return 2;
        }
        if ('array_multisort' === $lc) {
            return 0;
        }
        // php-src ext/mbstring/mbstring.stub.php — mixed &$var, mixed &...$vars (#25207 / #4572)
        if ('mb_convert_variables' === $lc) {
            return 2;
        }

        return null;
    }

    /**
     * Whether $argIndex is ZEND_SEND_REF for $name.
     * array_multisort() only passes array operands by reference, not SORT_* flags (#9481, ext/standard/array.c).
     */
    public static function isByRefArg(string $name, int $argIndex, ?Variable $runtimeValue = null): bool
    {
        $lc = strtolower($name);
        if ('array_multisort' === $lc) {
            if (null === $runtimeValue) {
                return false;
            }

            return Variable::TYPE_ARRAY === $runtimeValue->resolveIndirect()->type;
        }
        if (\in_array($argIndex, self::forFunction($lc), true)) {
            return true;
        }
        $variadicFrom = self::variadicByRefFromIndex($lc);
        if (null === $variadicFrom || $argIndex < $variadicFrom) {
            return false;
        }

        return true;
    }
}
