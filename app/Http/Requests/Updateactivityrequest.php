<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user is super admin (role_id = 1)
        return $this->user() && $this->user()->role_id === 1;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'type_id' => 'sometimes|required|exists:types,id',
            'title_en' => 'sometimes|required|string|max:255',
            'title_ar' => 'sometimes|required|string|max:255',
            'description_en' => 'nullable|string|max:10000',
            'description_ar' => 'nullable|string|max:10000',
            'location' => 'nullable|string|max:255',
            'event_date' => 'nullable|date',
            'status' => 'sometimes|required|in:upcoming,ongoing,completed,cancelled',
            'participants_count' => 'nullable|integer|min:0|max:1000000',
            'beneficiaries_count' => 'nullable|integer|min:0|max:1000000',
            'featured' => 'nullable|boolean',
            'media' => 'nullable|array|max:10',
            'media.*.file' => 'required|file|mimes:jpeg,jpg,png,gif,webp,mp4,avi,mov,wmv|max:51200',
            'media.*.type' => 'required|in:image,video',
            'delete_media' => 'nullable|array',
            'delete_media.*' => 'exists:activity_media,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'type_id.exists' => 'Selected activity type does not exist',
            'title_en.max' => 'English title cannot exceed 255 characters',
            'title_ar.max' => 'Arabic title cannot exceed 255 characters',
            'status.in' => 'Invalid status value',
            'media.max' => 'Maximum 10 media files allowed',
            'media.*.file.mimes' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, MP4, AVI, MOV, WMV',
            'media.*.file.max' => 'File size cannot exceed 50MB',
            'media.*.type.in' => 'Media type must be either image or video',
            'delete_media.*.exists' => 'One or more media items do not exist',
        ];
    }
}