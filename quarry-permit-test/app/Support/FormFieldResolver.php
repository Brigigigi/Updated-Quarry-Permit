<?php

namespace App\Support;

use Illuminate\Support\Str;

class FormFieldResolver
{
    public static function variants(string $key): array
    {
        $variants = [
            $key,
            Str::camel($key),
            Str::snake($key),
            Str::kebab($key),
            Str::lower($key),
            Str::upper($key),
            Str::lower(Str::snake($key)),
            Str::upper(Str::snake($key)),
            Str::studly($key),
            lcfirst(Str::studly($key)),
        ];

        $variants = array_filter($variants, static fn ($value) => $value !== null && $value !== '');

        return array_values(array_unique($variants));
    }

    public static function value(array $source, string $key, $default = '')
    {
        foreach (self::variants($key) as $candidate) {
            if (array_key_exists($candidate, $source)) {
                return $source[$candidate];
            }
        }

        return $default;
    }

    public static function map(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::value($source, $key);
        }

        return $result;
    }
}
