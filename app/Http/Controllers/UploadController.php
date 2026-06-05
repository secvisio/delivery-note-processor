<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files.*' => ['required', 'file', 'max:10240'], // 10MB
        ]);

        $diskName = config('delivery_note_processor.delivery_notes_disk', 'delivery_notes');
        $disk = Storage::disk($diskName);

        $sourceFolder = config('delivery_note_processor.source_folder');

        foreach ($request->file('files') as $file) {
            $relativePath = $sourceFolder . '/' . $this->formatFileName($file->getClientOriginalName());

            $disk->put($relativePath, $file->getContent());

            // TEMP DIAGNOSTIC: confirm where the upload actually lands on production.
            Log::info('Delivery-note upload stored', [
                'disk' => $diskName,
                'configured_source_folder' => config('delivery_note_processor.source_folder'),
                'relative_path' => $relativePath,
                'absolute_path' => $disk->path($relativePath),
                'exists_after_write' => $disk->exists($relativePath),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * @param string $filename
     * @return string
     */
    private function formatFileName(string $filename): string
    {
        return Str::of($filename)
            ->replaceMatches('/[^A-Za-z0-9.]+/', '_')
            ->lower()
            ->__toString();
    }
}
