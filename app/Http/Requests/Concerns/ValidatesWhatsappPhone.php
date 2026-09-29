<?php

namespace App\Http\Requests\Concerns;

use App\Support\WhatsappNumber;

/**
 * Le numéro WhatsApp d'une officine, normalisé avant d'être validé.
 *
 * Partagé par le profil d'inscription et la modification de l'officine : deux
 * copies de la règle finiraient par ranger le même numéro sous deux formes.
 * Un numéro qui ne se normalise pas est laissé tel quel, pour que la règle le
 * refuse avec son message plutôt que de disparaître en silence.
 */
trait ValidatesWhatsappPhone
{
    protected function prepareForValidation(): void
    {
        $raw = $this->input('whatsapp_phone');

        if (! is_string($raw) || trim($raw) === '') {
            $this->merge(['whatsapp_phone' => null]);

            return;
        }

        $this->merge(['whatsapp_phone' => WhatsappNumber::normalize($raw) ?? $raw]);
    }

    /**
     * @return array<int, string>
     */
    protected function whatsappPhoneRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:/^\+\d{8,15}$/'];
    }

    /**
     * @return array<string, string>
     */
    protected function whatsappPhoneMessages(): array
    {
        return [
            'whatsapp_phone.required' => 'Le numéro WhatsApp est obligatoire.',
            'whatsapp_phone.regex' => 'Numéro WhatsApp invalide.',
        ];
    }
}
