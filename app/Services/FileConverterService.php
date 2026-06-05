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
                // The converted image is a throwaway OCR INPUT. It must NOT be
                // written into the watched `source` folder — doing so triggers a
                // second inotify close_write event and a duplicate process — nor
                // into an output target folder. Write it to a dedicated,
                // NON-watched temp directory and return its ABSOLUTE path; the
                // caller resolves/reads it once and deletes it after OCR.
                $tmpDir = self::tempDir();

                $convertedFile = $tmpDir . DIRECTORY_SEPARATOR
                    . $this->getFileName($filePath) . '.' . $convertTo;

                // The installed Spatie API treats save()'s first argument as the
                // full image PATH (not a directory).
                (new Pdf($absoluteSource))->save($convertedFile);

                return $convertedFile;

            default:
                throw new Exception('No conversion source type given. e.g. "pdf"');
        }
    }

    /**
     * Absolute path of the non-watched scratch directory for converted images.
     * Created on demand. Kept outside storage/delivery-notes so inotify (which
     * watches only .../delivery-notes/source) never sees these files.
     */
    public static function tempDir(): string
    {
        $dir = storage_path('app/tmp/delivery-note-processor');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
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
