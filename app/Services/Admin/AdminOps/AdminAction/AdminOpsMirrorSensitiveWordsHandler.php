<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Models\SensitiveWord;
use Illuminate\Support\Facades\Validator;

/**
 * 敏感词只读/写入（不含改密；对齐 {@see SecuritySettingsController} 敏感词相关逻辑）。
 */
final class AdminOpsMirrorSensitiveWordsHandler
{
    /**
     * 列出全部敏感词。
     *
     * @return array<int, array{id:int,word:string,created_at:string}>
     */
    public function listWords(): array
    {
        return SensitiveWord::query()
            ->select(['id', 'word', 'created_at'])
            ->orderBy('word')
            ->get()
            ->map(static function (SensitiveWord $word): array {
                return [
                    'id' => (int) $word->id,
                    'word' => (string) $word->word,
                    'created_at' => (string) ($word->created_at?->format('Y-m-d') ?? ''),
                ];
            })
            ->all();
    }

    /**
     * 按行批量添加敏感词（去重、跳过已存在）。
     *
     * @return array{ok: bool, error?: string, inserted?: int, skipped_duplicates?: int}
     */
    public function addWords(string $multilineWords): array
    {
        $v = Validator::make(['words' => $multilineWords], ['words' => ['required', 'string']]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => 'words 不能为空。'];
        }
        $rawWords = trim($multilineWords);
        if ($rawWords === '') {
            return ['ok' => false, 'error' => 'words 不能为空。'];
        }
        $submittedWords = collect(preg_split('/\R/u', $rawWords) ?: [])
            ->map(static fn (string $word): string => trim($word))
            ->filter(static fn (string $word): bool => $word !== '')
            ->unique()
            ->values();
        if ($submittedWords->isEmpty()) {
            return ['ok' => false, 'error' => '无有效词条。'];
        }
        $existingWords = SensitiveWord::query()
            ->whereIn('word', $submittedWords->all())
            ->pluck('word')
            ->all();
        $wordsToInsert = $submittedWords
            ->reject(static fn (string $word): bool => in_array($word, $existingWords, true))
            ->map(static fn (string $word): array => [
                'word' => $word,
                'created_at' => now(),
            ])
            ->values()
            ->all();
        $inserted = 0;
        if ($wordsToInsert !== []) {
            SensitiveWord::query()->insert($wordsToInsert);
            $inserted = count($wordsToInsert);
        }

        return [
            'ok' => true,
            'inserted' => $inserted,
            'skipped_duplicates' => count($existingWords),
        ];
    }

    /**
     * 按主键删除一条敏感词。
     *
     * @return array{ok: bool, error?: string}
     */
    public function deleteWord(int $wordId): array
    {
        if ($wordId <= 0) {
            return ['ok' => false, 'error' => '无效的词 ID。'];
        }
        $deleted = SensitiveWord::query()->whereKey($wordId)->delete();
        if ($deleted <= 0) {
            return ['ok' => false, 'error' => '删除失败或记录不存在。'];
        }

        return ['ok' => true];
    }
}
