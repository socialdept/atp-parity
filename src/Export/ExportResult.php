<?php

namespace SocialDept\AtpParity\Export;

/**
 * Value object representing the result of an export operation.
 */
readonly class ExportResult
{
    public function __construct(
        public bool $success,
        public ?string $path = null,
        public ?int $size = null,
        public ?string $error = null,
    ) {}

    /**
     * Check if the export operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the export operation failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Create a successful result.
     */
    public static function success(string $path, int $size): self
    {
        return new self(
            success: true,
            path: $path,
            size: $size,
        );
    }

    /**
     * Create a failed result.
     */
    public static function failed(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
