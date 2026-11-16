<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Ente;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Slug dell'ente unico sul valore che si salva, cioè già normalizzato (oc:8965): «Comune di Lodè»
 * si confronta come `comune-di-lode`. La regola `unique` di Laravel guarderebbe l'input grezzo.
 */
class UniqueEnteSlug implements ValidationRule
{
    public function __construct(private readonly int|string|null $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = Ente::normalizeSlug(is_scalar($value) ? (string) $value : null, null);
        if ($slug === null) {
            return;
        }

        $taken = Ente::query()
            ->where('slug', $slug)
            ->when($this->ignoreId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail(__('validation.unique', ['attribute' => $attribute]));
        }
    }
}
