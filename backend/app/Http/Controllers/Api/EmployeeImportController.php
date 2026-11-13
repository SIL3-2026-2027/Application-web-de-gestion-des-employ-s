<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmployeeExportService;
use App\Services\EmployeeImportService;
use Illuminate\Http\Request;

/** EMP-07 : import des employés (RH). Étapes : modèle → aperçu contrôlé → import. */
class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImportService $importer) {}

    public function template(EmployeeExportService $exporter)
    {
        return $exporter->template();
    }

    public function preview(Request $request)
    {
        $file = $this->validateFile($request);

        return $this->importer->preview($file->getRealPath(), $this->extension($file), $request->boolean('create_missing'));
    }

    public function store(Request $request)
    {
        $file = $this->validateFile($request);

        $result = $this->importer->import(
            $request->user(),
            $file->getRealPath(),
            $this->extension($file),
            $request->boolean('create_missing'),
            $request->boolean('only_valid'),
        );

        return response()->json($result, 201);
    }

    private function validateFile(Request $request): \Illuminate\Http\UploadedFile
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
            'create_missing' => ['boolean'],
            'only_valid' => ['boolean'],
        ], ['file.mimes' => 'Format accepté : Excel (.xlsx) ou CSV.', 'file.max' => 'Le fichier ne doit pas dépasser 5 Mo.'], ['file' => 'fichier']);

        return $request->file('file');
    }

    private function extension(\Illuminate\Http\UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
    }
}
