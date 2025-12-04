<?php

namespace SocialDept\AtpParity\Export;

/**
 * Value object representing an exported repository as CAR data.
 */
readonly class RepoExport
{
    public function __construct(
        public string $did,
        public string $carData,
        public int $size,
    ) {}

    /**
     * Save the CAR data to a file.
     */
    public function saveTo(string $path): bool
    {
        return file_put_contents($path, $this->carData) !== false;
    }

    /**
     * Get the size in human-readable format.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2).' '.$units[$unit];
    }
}
