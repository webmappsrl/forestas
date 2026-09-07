<?php

namespace App\Http\Requests\Api\Sus;

use Illuminate\Foundation\Http\FormRequest;

// Nota interna (non finisce nella documentazione pubblica: e' un commento, non
// un docblock): la validazione vive qui e non inline nel controller perche'
// cosi' il generatore ne ricava uno schema riutilizzabile, che compare nella
// sezione "Schemas" della documentazione consegnata a Engineering. oc:8333

/**
 * Credenziali con cui il client SUS ottiene un token di accesso.
 */
class SusLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Email del client SUS, quella comunicata insieme alle credenziali.
             *
             * @example sus@catasto.invalid
             */
            'email' => ['required', 'email'],

            /**
             * Password del client SUS.
             *
             * @example la-password-comunicata
             */
            'password' => ['required', 'string'],
        ];
    }
}
