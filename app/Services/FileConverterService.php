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

        // Convert relative → absolute for the external library.
        $absoluteSource = $disk->path($filePath);

        switch ($convertFrom) {
            case 'pdf':
                // The converted image is the OCR INPUT, so it must live in the
                // technical source folder next to the original — NOT in an
                // output target folder (Lieferscheine/etc.). Same base name,
                // new extension, kept relative to the disk.
                $convertedFile = config('delivery_note_processor.source_folder')
                    . DIRECTORY_SEPARATOR . $this->getFileName($filePath) . '.' . $convertTo;

                // The installed Spatie API treats save()'s first argument as the
                // full image PATH (not a directory), so pass an absolute file
                // path. Passing a directory previously produced a mangled name
                // like "<name><targetFolder>.jpg".
                (new Pdf($absoluteSource))->save($disk->path($convertedFile));

                return $convertedFile;

            default:
                throw new Exception('No conversion source type given. e.g. "pdf"');
        }
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
