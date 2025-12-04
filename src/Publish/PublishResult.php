<?php

namespace SocialDept\AtpParity\Publish;

/**
 * Immutable value object representing the result of a publish operation.
 */
readonly class PublishResult
{
    public function __construct(
        public bool $success,
        public ?string $uri = null,
        public ?string $cid = null,
        public ?string $error = null,
    ) {}

    /**
     * Check if the publish operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the publish operation failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Create a successful result.
     */
    public static function success(string $uri, string $cid): self
    {
        return new self(
            success: true,
            uri: $uri,
            cid: $cid,
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
