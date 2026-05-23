/*
 * crc32() runtime for AOT/JIT (issue #1014).
 * CRC32B (IEEE / zlib polynomial 0xEDB88320), signed 32-bit return.
 */

#include <stdint.h>
#include <stdlib.h>

typedef struct __string__ __string__;

static size_t crc32_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const unsigned char *crc32_strdata(__string__ *s)
{
    if (NULL == s) {
        return (const unsigned char *) "";
    }

    return (const unsigned char *) s + sizeof(void *) + sizeof(long long);
}

static uint32_t crc32b_table[256];
static int crc32b_table_ready;

static void crc32b_init_table(void)
{
    if (crc32b_table_ready) {
        return;
    }
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++) {
            c = (c & 1) ? (0xEDB88320u ^ (c >> 1)) : (c >> 1);
        }
        crc32b_table[i] = c;
    }
    crc32b_table_ready = 1;
}

static uint32_t crc32b_compute(uint32_t crc, const unsigned char *buf, size_t len)
{
    crc32b_init_table();
    crc ^= 0xFFFFFFFFu;
    for (size_t i = 0; i < len; i++) {
        crc = crc32b_table[(crc ^ buf[i]) & 0xFFu] ^ (crc >> 8);
    }

    return crc ^ 0xFFFFFFFFu;
}

long long __compiler_crc32(__string__ *str, long long seed)
{
    uint32_t crc = (uint32_t) seed;
    size_t len = crc32_strlen(str);
    const unsigned char *data = crc32_strdata(str);
    uint32_t result = crc32b_compute(crc, data, len);

    return (long long) (uint32_t) result;
}
