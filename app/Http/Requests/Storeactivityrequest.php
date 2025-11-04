<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
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
            'type_id' => 'required|exists:types,id',
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'description_en' => 'nullable|string|max:10000',
            'description_ar' => 'nullable|string|max:10000',
            'location' => 'nullable|string|max:255',
            'event_date' => 'nullable|date|after_or_equal:today',
            'status' => 'required|in:upcoming,ongoing,completed,cancelled',
            'participants_count' => 'nullable|integer|min:0|max:1000000',
            'beneficiaries_count' => 'nullable|integer|min:0|max:1000000',
            'featured' => 'nullable|boolean',
            'media' => 'nullable|array|max:10', // Maximum 10 media files
            'media.*.file' => 'required|file|mimes:jpeg,jpg,png,gif,webp,mp4,avi,mov,wmv|max:51200', // 50MB
            'media.*.type' => 'required|in:image,video',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'type_id.required' => 'Activity type is required',
            'type_id.exists' => 'Selected activity type does not exist',
            'title_en.required' => 'English title is required',
            'title_ar.required' => 'Arabic title is required',
            'title_en.max' => 'English title cannot exceed 255 characters',
            'title_ar.max' => 'Arabic title cannot exceed 255 characters',
            'event_date.after_or_equal' => 'Event date must be today or in the future',
            'status.required' => 'Status is required',
            'status.in' => 'Invalid status value',
            'media.max' => 'Maximum 10 media files allowed',
            'media.*.file.required' => 'Media file is required',
            'media.*.file.mimes' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, MP4, AVI, MOV, WMV',
            'media.*.file.max' => 'File size cannot exceed 50MB',
            'media.*.type.required' => 'Media type is required',
            'media.*.type.in' => 'Media type must be either image or video',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'type_id' => 'activity type',
            'title_en' => 'English title',
            'title_ar' => 'Arabic title',
            'description_en' => 'English description',
            'description_ar' => 'Arabic description',
            'event_date' => 'event date',
            'participants_count' => 'participants count',
            'beneficiaries_count' => 'beneficiaries count',
        ];
    }
}