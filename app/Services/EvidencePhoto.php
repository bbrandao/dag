<?php

namespace App\Services;

use App\Repositories\MissionRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class EvidencePhoto
{
    public function __construct(private readonly MissionRepository $missions) {}

    public function store(UploadedFile $file, string $submissionId): string
    {
        $info = @getimagesize($file->getPathname());

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 24000000) {
            $this->invalid();
        }

        $image = @imagecreatefromstring($file->getContent());

        if ($image === false) {
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

    private function invalid(): never
    {
        throw ValidationException::withMessages(['photo' => 'Escolha uma foto JPEG, PNG ou WebP com até 24 megapixels.']);
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
