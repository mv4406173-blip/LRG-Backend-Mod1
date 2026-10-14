<?php

namespace App\Http\Requests;

use App\Models\SchemaImport;
use App\Services\SchemaImportService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSchemaImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) ceil(SchemaImportService::MAX_FILE_SIZE_BYTES / 1024);

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
            ],
            'dialect' => [
                'required',
                'string',
                'in:'.implode(',', SchemaImport::SUPPORTED_DIALECTS),
            ],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $file = $this->file('file');

            if (! $file) {
                return;
            }

            if ($file->getSize() > SchemaImportService::MAX_FILE_SIZE_BYTES) {
                $validator->errors()->add('file', 'El archivo supera el tamaño máximo permitido.');

                return;
            }

            if ($file->getSize() < 1) {
                $validator->errors()->add('file', 'El archivo SQL no puede estar vacío.');

                return;
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, ['sql'], true)) {
                $validator->errors()->add('file', 'Solo se admiten archivos con extensión .sql.');
            }
        });
    }
}
