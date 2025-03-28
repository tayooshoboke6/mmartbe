<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get common validation rules
     *
     * @return array
     */
    protected function getCommonRules()
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }

    /**
     * Get common validation messages
     *
     * @return array
     */
    protected function getCommonMessages()
    {
        return [
            'name.required' => 'The name field is required.',
            'name.max' => 'The name may not be greater than 255 characters.',
            'is_active.boolean' => 'The is_active field must be true or false.',
            'created_at.date' => 'The created_at field must be a valid date.',
            'updated_at.date' => 'The updated_at field must be a valid date.',
        ];
    }

    /**
     * Get common validation attributes
     *
     * @return array
     */
    protected function getCommonAttributes()
    {
        return [
            'name' => 'Name',
            'description' => 'Description',
            'is_active' => 'Active Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
} 