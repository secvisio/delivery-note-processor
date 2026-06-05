<?php
declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use Spatie\PdfToImage\Pdf;

class FileConverterService
{

    /**
     * @param string $targetPath
     */
    public function __construct(public string $targetPath) {}

    /**
     * @param string $filePath
     * @param string $convertFrom
     * @param string $convertTo
     * @return string
     * @throws PdfDoesNotExist
     */
    public function run(string $filePath, string $convertFrom, string $convertTo = 'jpg'): string
    {
        $disk = Storage::disk(config('delivery_note_processor.delivery_notes_disk'));

        // Convert relative → absolute for the external library
        $absoluteSource = $disk->path($filePath);
        $absoluteTargetDir = $disk->path(config('delivery_note_processor.target_folder'));

        $convertedFile = null;

        switch ($convertFrom) {
            case 'pdf':
                $pdf = new Pdf($absoluteSource);

                $savedFile = $pdf->save(
                    $absoluteTargetDir,
                    $this->getFileName($filePath)
                );

                // Always hand back a clean RELATIVE path on the delivery_notes
                // disk. Taking only basename() of whatever absolute path the
                // library returns avoids fragile prefix matching (which could
                // leak the absolute path) and guarantees the caller resolves it
                // to absolute exactly once.
                $convertedFile = config('delivery_note_processor.target_folder')
                    . DIRECTORY_SEPARATOR . basename($savedFile[0]);
                break;

            default:
                throw new Exception('No conversion source type given. e.g. "pdf"');
        }

        return $convertedFile;
    }

    /**
     * @param $file
     * @return string
     */
    private function getFileName($file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }
}
