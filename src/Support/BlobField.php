<?php

namespace SocialDept\AtpParity\Support;

/**
 * Helper for defining blob field configurations in mappers.
 *
 * @example
 * public function blobFields(): array
 * {
 *     return [
 *         'icon' => BlobField::single('icon'),
 *         'images' => BlobField::array('embed.images.*.image'),
 *     ];
 * }
 */
class BlobField
{
    /**
     * Define a single blob field.
     *
     * @param  string|null  $path  Dot-notation path to the blob in the record (defaults to field name)
     * @return array{type: 'single', path?: string}
     */
    public static function single(?string $path = null): array
    {
        $config = ['type' => 'single'];

        if ($path !== null) {
            $config['path'] = $path;
        }

        return $config;
    }

    /**
     * Define an array of blobs field.
     *
     * @param  string|null  $path  Dot-notation path to the blobs in the record (defaults to field name)
     * @return array{type: 'array', path?: string}
     */
    public static function array(?string $path = null): array
    {
        $config = ['type' => 'array'];

        if ($path !== null) {
            $config['path'] = $path;
        }

        return $config;
    }
}
