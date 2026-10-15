<?php

namespace App\Http\Requests\Admin;

use App\Models\City;
use App\Models\Region;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class SaveRegionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|Exists|Unique>>
     */
    public function rules(): array
    {
        $region = $this->route('region');
        $regionId = $region instanceof Region ? $region->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique(Region::class, 'name')->ignore($regionId),
            ],
            'city_ids' => ['present', 'array'],
            // Une ville libre, ou déjà dans cette région : jamais celle d'une
            // autre région. Le schéma ne garde qu'un region_id par ville, donc
            // laisser passer l'id l'arracherait en silence à sa région.
            'city_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(City::class, 'id')->where(
                    fn (Builder $query) => $query->whereNull('region_id')->orWhere('region_id', $regionId),
                ),
            ],
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la région est obligatoire.',
            'name.unique' => 'Une région porte déjà ce nom.',
            'city_ids.*.exists' => 'Une des villes choisies appartient déjà à une autre région.',
        ];
    }
}
