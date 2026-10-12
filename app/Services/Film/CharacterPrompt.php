<?php

namespace App\Services\Film;

use App\Models\SeriesCharacter;

/** Prompt vẽ ảnh nhân vật theo loại (ảnh chính, bảng xoay, biểu cảm, tự mô tả); mọi loại kèm mô tả ngoại hình cố định + câu style của bộ phim. */
final class CharacterPrompt
{
    public static function build(SeriesCharacter $character, string $kind, ?string $notes, bool $hasReference): string
    {
        $who = trim("{$character->name}".($character->age !== null ? ", {$character->age} years old" : ''));
        $same = $hasReference
            ? 'It is exactly the same character as in the attached image: same face, hair, body proportions, outfit and colors. '
            : '';

        $body = match ($kind) {
            'turnaround' => "Character turnaround sheet of {$who}: the same character drawn three times side by side at the same size, "
                .'front view, side view and back view, full body from head to toe, neutral standing pose, plain light gray background.',
            'expressions' => "Expression sheet of {$who}: six head-and-shoulders drawings of the same character in a 3x2 grid, "
                .'showing happy, sad, surprised, angry, scared and thinking, plain light background.',
            'custom' => "{$who}: ".trim((string) $notes).'. Full body visible, simple background that does not distract.',
            default => "Character design of {$who}: full body from head to toe, standing in a relaxed neutral pose facing the viewer, "
                .'centered with space around, plain light background, no other characters.',
        };

        $extra = $kind !== 'custom' && filled($notes) ? ' Additional direction: '.trim($notes).'.' : '';

        return "{$body}{$extra}\n\n{$same}Character appearance (keep every detail): {$character->appearance}\n\n"
            ."Art style: {$character->series->style}.";
    }
}
