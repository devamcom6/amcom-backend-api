<?php

namespace App\Http\Requests;

use App\Enums\MediaCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin media library upload (contract §14.2). */
class UploadMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => Rules::image((int) config('amcom.media.max_photo_kb'), 100, 100),
            'collection' => ['required', Rule::in([
                MediaCollection::Content->value,
                MediaCollection::EstateGallery->value,
                MediaCollection::PropertyGallery->value,
            ])],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
