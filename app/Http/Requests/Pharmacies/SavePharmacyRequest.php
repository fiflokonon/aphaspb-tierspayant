<?php

namespace App\Http\Requests\Pharmacies;

use App\Http\Requests\Concerns\ValidatesWhatsappPhone;
use App\Rules\PharmacyName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePharmacyRequest extends FormRequest
{
    use ValidatesWhatsappPhone;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new PharmacyName],
            'whatsapp_phone' => $this->whatsappPhoneRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->whatsappPhoneMessages();
    }
}
