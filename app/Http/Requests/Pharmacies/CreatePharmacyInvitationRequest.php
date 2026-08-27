<?php

namespace App\Http\Requests\Pharmacies;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Rules\UniquePharmacyInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePharmacyInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pharmacy = $this->route('pharmacy');

        abort_if(! $pharmacy instanceof Pharmacy, 404);

        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniquePharmacyInvitation($pharmacy)],
            'role' => ['required', 'string', Rule::enum(PharmacyRole::class)],
        ];
    }
}
