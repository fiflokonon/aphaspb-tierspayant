<?php

namespace App\Http\Requests\Pharmacies;

use App\Models\Pharmacy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class DeletePharmacyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->route('pharmacy'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('name') !== $this->pharmacy()->name) {
                    $validator->errors()->add('name', __('The pharmacy name does not match.'));
                }
            },
        ];
    }

    /**
     * Get the pharmacy associated with the request.
     */
    private function pharmacy(): Pharmacy
    {
        $pharmacy = $this->route('pharmacy');

        abort_if(! $pharmacy instanceof Pharmacy, 404);

        return $pharmacy;
    }
}
