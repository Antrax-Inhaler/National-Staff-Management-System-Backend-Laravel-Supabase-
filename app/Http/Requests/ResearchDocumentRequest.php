<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResearchDocumentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $currentYear = date('Y');
        
        $rules = [
            'title' => 'required|string|max:255',
            'type' => ['required', Rule::in(['contract', 'arbitration', 'mou', 'bylaws', 'research', 'general'])],
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'affiliate_id' => 'nullable|exists:affiliates,id',
            'folder_id' => 'nullable|exists:document_folders,id',
            'employer' => 'nullable|string|max:255',
            'cbc' => 'nullable|string|max:255',
            'state' => 'nullable|string',
            'keywords' => 'nullable|string',
            'sub_type' => 'nullable|string|max:255',
            'year' => "nullable|integer|min:1900|max:{$currentYear}",
            'is_public' => 'nullable|boolean',
        ];

        if ($this->isMethod('post')) {
            // Store-specific rules
            $rules['expiration_date'] = 'nullable|date|after:today';
            $rules['effective_date'] = 'nullable|date';
            $rules['status'] = ['nullable', Rule::in(['active', 'expired', 'negotiation', 'draft'])];
            $rules['database_source'] = ['required', Rule::in(['contracts', 'arbitrations', 'mous', 'research_collection', 'general'])];
            $rules['is_archived'] = 'nullable|boolean';
            $rules['file'] = 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240';
        }

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            // Update-specific rules
            $rules['contract_expiration_date'] = 'nullable|date|after:today';
            $rules['effective_date'] = 'nullable|date';
            $rules['status'] = ['nullable', Rule::in(['active', 'expired', 'negotiation', 'draft'])];
            $rules['database_source'] = ['required', Rule::in(['contracts', 'arbitrations', 'mous', 'research_collection', 'general'])];
            $rules['is_archived'] = 'nullable|boolean';
            $rules['file'] = 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240';
        }

        return $rules;
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // Normalize boolean fields
        $booleanFields = ['is_public', 'is_archived'];
        foreach ($booleanFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = filter_var($validated[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        
        // Set category_group to 'research' (fixed for this controller)
        $validated['category_group'] = 'research';
        
        return $validated;
    }
}