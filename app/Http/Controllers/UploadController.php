<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        foreach ($request->file('files') as $file) {
            Storage::disk('delivery_notes')->put(
                'source/'.$this->formatFileName($file->getClientOriginalName()),
                $file->getContent()
            );
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
