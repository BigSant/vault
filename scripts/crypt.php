<?php

declare(strict_types=1);
function writeAll($stream, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = @fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup write failed');
        }
        $offset += $written;
    }
}
umask(0077);
// Authenticated streaming encryption: no key or plaintext on argv/stdout.
if (count($argv) !== 5 || ! in_array($argv[1], ['encrypt', 'decrypt'], true)) {
    exit(2);
}
[$script,$mode,$keyPath,$sourcePath,$targetPath] = $argv;
$key = file_get_contents($keyPath);
if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
    exit(2);
}
$source = @fopen($sourcePath, 'rb');
if ($source === false) {
    exit(2);
}
$target = @fopen($targetPath, 'xb');
if ($target === false) {
    fclose($source);
    exit(2);
}
try {
    if ($mode === 'encrypt') {
        [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        writeAll($target, 'VLT1'.$header);
        while (! feof($source)) {
            $plain = fread($source, 65536);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            writeAll($target, pack('N', strlen($cipher)).$cipher);
        }
        $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        writeAll($target, pack('N', strlen($final)).$final);
    } else {
        if (fread($source, 4) !== 'VLT1') {
            throw new RuntimeException;
        }
        $header = fread($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $finished = false;
        while (! feof($source)) {
            $size = fread($source, 4);
            if (strlen($size) !== 4) {
                throw new RuntimeException;
            }
            $size = unpack('N', $size)[1];
            if ($size < 17 || $size > 65553) {
                throw new RuntimeException;
            }
            $chunk = fread($source, $size);
            if (strlen($chunk) !== $size) {
                throw new RuntimeException;
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($result === false) {
                throw new RuntimeException;
            }
            [$plain,$tag] = $result;
            writeAll($target, $plain);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if (fread($source, 1) !== '') {
                    throw new RuntimeException;
                }$finished = true;
                break;
            }
        }
        if (! $finished) {
            throw new RuntimeException;
        }
    }
    if (! @fflush($target) || ! @fsync($target)) {
        throw new RuntimeException('Backup sync failed');
    }
} catch (Throwable) {
    fclose($target);
    unlink($targetPath);
    fwrite(STDERR, "Backup authentication/encryption failed.\n");
    exit(1);
} finally {
    sodium_memzero($key);
    fclose($source);
}
