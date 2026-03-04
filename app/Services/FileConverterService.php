<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
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
     * @throws \Spatie\PdfToImage\Exceptions\PdfDoesNotExist
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

                // If library returns absolute path, convert back to relative
                $absoluteResult = $savedFile[0];

                $convertedFile = str_replace(
                    $absoluteTargetDir . DIRECTORY_SEPARATOR,
                    config('delivery_note_processor.target_folder') . DIRECTORY_SEPARATOR,
                    $absoluteResult
                );
                break;

            default:
                throw new \Exception('No conversion source type given. e.g. "pdf"');
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
