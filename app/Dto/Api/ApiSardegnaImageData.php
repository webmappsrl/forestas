<?php

declare(strict_types=1);

namespace App\Dto\Api;

final readonly class ApiSardegnaImageData
{
    public function __construct(
        public string $url,
        public string $autore,
        public string $credits,
    ) {}

    /**
     * @param  mixed  $value  API object under immagine_principale / galleria item
     */
    public static function tryFromMixed(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $url = isset($value['url']) ? trim((string) $value['url']) : '';
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return new self(
            url: $url,
            autore: isset($value['autore']) ? (string) $value['autore'] : '',
            credits: isset($value['credits']) ? (string) $value['credits'] : '',
        );
    }
}
