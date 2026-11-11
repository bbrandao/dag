<?php

namespace App\Services;

use App\Repositories\MissionRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class EvidencePhoto
{
    public function __construct(private readonly MissionRepository $missions) {}

    public function store(UploadedFile $file, string $submissionId): string
    {
        $info = @getimagesize($file->getPathname());
        $contents = $file->getContent();

        if ($info === false && $this->isHeif($contents)) {
            $contents = $this->convertHeif($file->getPathname());
            $info = @getimagesizefromstring($contents);
        }
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 24000000) {
            Log::notice('DAG: foto recebida não pôde ser validada.', [
                'received_bytes' => strlen($contents),
                'mime' => $file->getMimeType(),
                'width' => $info === false ? null : $info[0],
                'height' => $info === false ? null : $info[1],
            ]);
            $this->invalid();
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            Log::notice('DAG: falha na decodificação da foto.', ['received_bytes' => strlen($contents), 'mime' => $info['mime']]);
            $this->invalid();
        }

        $encoded = tmpfile();
        if ($encoded === false) {
            throw new RuntimeException('Não foi possível preparar a foto.');
        }

        $path = "mission-photos/{$submissionId}.jpg";

        try {
            if (! imagejpeg($image, $encoded, 85) || ! rewind($encoded)
                || ! Storage::disk('local')->put($path, $encoded)) {
                throw new RuntimeException('Não foi possível guardar a foto.');
            }
        } finally {
            fclose($encoded);
        }

        return $path;
    }

    private function isHeif(string $contents): bool
    {
        if (substr($contents, 4, 4) !== 'ftyp') {
            return false;
        }

        $brands = str_split(substr($contents, 8, 64), 4);

        return array_intersect($brands, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1']) !== [];
    }

    private function convertHeif(string $path): string
    {
        $process = new Process([
            'convert', '-limit', 'thread', '1', '-limit', 'memory', '128MiB', '-limit', 'map', '0',
            '-limit', 'disk', '0', '-limit', 'width', '10000', '-limit', 'height', '10000',
            'HEIC:'.$path.'[0]', '-auto-orient', '-strip', '-resize', '2048x2048>', '-quality', '85', 'JPEG:-',
        ], timeout: 8);

        try {
            if ($process->run() === 0 && $process->getOutput() !== '') {
                return $process->getOutput();
            }
        } catch (ProcessTimedOutException) {
            $this->invalid();
        }

        $this->invalid();
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['photo' => 'Não conseguimos abrir esta foto. Tente tirar outra ou escolher uma imagem JPEG, PNG, WebP ou HEIC da galeria.']);
    }

    public function prune(): void
    {
        $this->missions->eachExpiredPhoto(function (object $submission): void {
            if ($this->remove($submission->photo_path)) {
                $this->missions->clearPhoto($submission->id);
            }
        });
    }

    public function remove(string $path): bool
    {
        return Storage::disk('local')->delete($path);
    }
}
