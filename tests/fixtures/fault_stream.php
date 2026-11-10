<?php

// Test-only output sink: inject ENOSPC/short-write/flush errors without filling a disk.
final class FaultStream
{
    public $context;

    private $file;

    private int $written = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->file = fopen(substr($path, 8), $mode);

        return $this->file !== false;
    }

    public function stream_write($data): int|false
    {
        $fault = getenv('VAULT_TEST_STREAM_FAULT');
        if ($fault === 'write' || ($fault === 'short' && $this->written >= 8)) {
            return false;
        }
        $bytes = $fault === 'short' ? substr($data, 0, 8) : $data;
        $count = fwrite($this->file, $bytes);
        if ($count !== false) {
            $this->written += $count;
        }

        return $count;
    }

    public function stream_flush(): bool
    {
        return getenv('VAULT_TEST_STREAM_FAULT') === 'flush' ? false : fflush($this->file);
    }

    public function stream_close(): void
    {
        fclose($this->file);
    }

    public function unlink($path): bool
    {
        return unlink(substr($path, 8));
    }
}
stream_wrapper_register('fault', FaultStream::class);
