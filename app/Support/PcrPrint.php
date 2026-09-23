<?php

namespace App\Support;

use App\Models\User;
use App\Services\RatingScale;

/**
 * Shared bits of the printed OPCR/IPCR. The LGU seal is optional until the
 * file is dropped in public/images — the title still prints without it.
 */
class PcrPrint
{
    public const SEAL_FILES = ['lgu-seal.webp', 'lgu-seal.png', 'lgu-seal.jpg', 'lgu-seal.jpeg'];

    public static function sealPath(): ?string
    {
        foreach (self::SEAL_FILES as $name) {
            $path = public_path('images/'.$name);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * DomPDF on Windows needs a data URI when the project path has spaces.
     */
    public static function sealSrc(): ?string
    {
        $path = self::sealPath();

        if (! $path) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    public static function sealUrl(): ?string
    {
        foreach (self::SEAL_FILES as $name) {
            if (is_file(public_path('images/'.$name))) {
                return '/images/'.$name;
            }
        }

        return null;
    }

    public static function officeHead(): ?User
    {
        return User::query()
            ->where('role', 'president')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    public static function scale(): array
    {
        $ranges = [
            5 => '100%',
            4 => '90-99%',
            3 => '70-89%',
            2 => '50-69%',
            1 => 'Below 50%',
        ];

        return array_map(function (array $band) use ($ranges) {
            $value = (int) ($band['value'] ?? 0);

            return $band + ['range' => $band['range'] ?? ($ranges[$value] ?? null)];
        }, RatingScale::bands());
    }
}
