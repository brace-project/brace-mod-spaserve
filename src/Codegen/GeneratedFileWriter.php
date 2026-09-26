<?php

declare(strict_types=1);

namespace Brace\SpaServe\Codegen;

use RuntimeException;

final class GeneratedFileWriter
{
    /** Returns true only when the target file was actually changed. */
    public function writeIfChanged(string $targetFile, string $contents): bool
    {
        $current = is_file($targetFile) ? file_get_contents($targetFile) : false;
        if ($current !== false && hash_equals(hash('sha256', $current), hash('sha256', $contents))) return false;

        $dir = dirname($targetFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException("Cannot create output directory: $dir");

        $tmp = tempnam($dir, '.' . basename($targetFile) . '.tmp-');
        if ($tmp === false) throw new RuntimeException("Cannot create temporary file in: $dir");
        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) throw new RuntimeException("Cannot write generated file: $tmp");
            if (!rename($tmp, $targetFile)) throw new RuntimeException("Cannot replace generated file: $targetFile");
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
        return true;
    }
}
