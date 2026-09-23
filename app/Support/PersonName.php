<?php

namespace App\Support;

class PersonName
{
    public const PREFIXES = ['dr', 'dr.', 'engr', 'engr.', 'atty', 'atty.', 'prof', 'prof.', 'hon', 'hon.', 'rev', 'rev.', 'mr', 'mr.', 'ms', 'ms.', 'mrs', 'mrs.'];

    public const SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v', 'vi'];

    public static function initial(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_strtoupper(mb_substr($value, 0, 1)) . '.';
    }

    public static function compose(array $parts): string
    {
        $core = collect([
            $parts['prefix']         ?? null,
            $parts['first_name']     ?? null,
            $parts['middle_initial'] ?? null,
            $parts['last_name']      ?? null,
            $parts['suffix']         ?? null,
        ])->map(fn ($piece) => trim((string) $piece))->filter()->implode(' ');

        $credentials = trim((string) ($parts['credentials'] ?? ''));

        return $credentials === '' ? $core : trim($core . ', ' . $credentials);
    }

    private static function looksLikeInitial(string $token): bool
    {
        return (bool) preg_match('/^\p{L}\.?$/u', $token);
    }

    public static function parse(?string $name): array
    {
        $blank = [
            'prefix' => null, 'first_name' => null, 'middle_initial' => null,
            'last_name' => null, 'suffix' => null, 'credentials' => null,
        ];

        $name = trim((string) $name);

        if ($name === '') {
            return $blank;
        }

        $credentials = null;

        if (str_contains($name, ',')) {
            $pieces = explode(',', $name);
            $tail   = trim(array_pop($pieces));

            if ($tail !== '' && ! in_array(mb_strtolower($tail), self::SUFFIXES, true)) {
                $credentials = $tail;
                $name        = trim(implode(',', $pieces));
            }
        }

        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefix = null;
        $suffix = null;

        if (count($tokens) > 1 && in_array(mb_strtolower($tokens[0]), self::PREFIXES, true)) {
            $prefix = array_shift($tokens);
        }

        if (count($tokens) > 1 && in_array(mb_strtolower(end($tokens)), self::SUFFIXES, true)) {
            $suffix = array_pop($tokens);
        }

        $last = count($tokens) > 1 ? array_pop($tokens) : null;

        $initial = null;

        if (count($tokens) > 1 && self::looksLikeInitial(end($tokens))) {
            $initial = self::initial(array_pop($tokens));
        }

        return [
            'prefix'         => $prefix,
            'first_name'     => count($tokens) ? implode(' ', $tokens) : null,
            'middle_initial' => $initial,
            'last_name'      => $last,
            'suffix'         => $suffix,
            'credentials'    => $credentials,
        ];
    }
}
