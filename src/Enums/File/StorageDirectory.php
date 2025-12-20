<?php

namespace InFlow\Enums\File;

/**
 * Enum for Laravel storage directories used by InFlow.
 *
 * Provides type-safe access to common storage directories and their
 * absolute path resolution.
 */
enum StorageDirectory: string
{
    case StorageApp = 'storage/app';
    case StorageAppPublic = 'storage/app/public';
    case Public = 'public';
    case StorageAppInflow = 'storage/app/inflow';
    case PublicInflow = 'public/inflow';

    /**
     * Get the absolute path for this storage directory.
     *
     * @return string Absolute path to the directory
     */
    public function getAbsolutePath(): string
    {
        return match ($this) {
            self::StorageApp => storage_path('app'),
            self::StorageAppPublic => storage_path('app/public'),
            self::Public => public_path(),
            self::StorageAppInflow => base_path('storage/app/inflow'),
            self::PublicInflow => public_path('inflow'),
        };
    }

    /**
     * Get all default storage directories for file discovery.
     *
     * @return array<self> Array of storage directory enums
     */
    public static function defaultDirectories(): array
    {
        return [
            self::StorageApp,
            self::StorageAppPublic,
            self::Public,
            self::StorageAppInflow,
            self::PublicInflow,
        ];
    }
}

