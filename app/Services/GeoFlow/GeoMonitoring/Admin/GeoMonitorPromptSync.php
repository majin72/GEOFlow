<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring\Admin;

use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorListParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 将项目表单中的「每行一个问题」同步到 geo_monitor_prompts 表。
 *
 * 使用行序稳定 code（q_001、q_002…），避免中文问题修改后 md5 变化产生重复记录。
 */
class GeoMonitorPromptSync
{
    /**
     * 根据多行文本同步项目监测问题集。
     *
     * @param  GeoMonitorProject  $project  监测项目
     * @param  string|null  $questionsText  每行一个问题的文本
     */
    public function syncFromTextarea(GeoMonitorProject $project, ?string $questionsText): void
    {
        $lines = GeoMonitorListParser::parse($questionsText);
        $codesInForm = [];

        DB::transaction(function () use ($project, $lines, &$codesInForm): void {
            foreach ($lines as $index => $line) {
                $code = self::promptCodeFromIndex($index);
                $codesInForm[] = $code;

                GeoMonitorPrompt::query()->updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'code' => $code,
                    ],
                    [
                        'prompt_text' => $line,
                        'intent' => 'generic',
                        'is_enabled' => true,
                        'priority' => $index,
                    ],
                );
            }

            $stalePrompts = GeoMonitorPrompt::query()
                ->where('project_id', $project->id)
                ->when($codesInForm !== [], fn ($query) => $query->whereNotIn('code', $codesInForm))
                ->get();

            foreach ($stalePrompts as $prompt) {
                if ($prompt->observations()->exists()) {
                    $prompt->update(['is_enabled' => false]);

                    continue;
                }

                $prompt->delete();
            }
        });
    }

    /**
     * 根据行序生成稳定的 prompt code（第 1 行 → q_001）。
     *
     * @param  int  $index  从 0 开始的行号
     */
    public static function promptCodeFromIndex(int $index): string
    {
        return 'q_'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * 根据问题正文生成 sidecar 证据文件名用的内部 code（仅导入/兼容旧逻辑）。
     *
     * 短文本优先用 slug；过长或无法 slug 时使用 q_{md5 前 16 位}。
     *
     * @param  string  $text  问题正文
     */
    public static function promptCodeFromText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return 'q_'.substr(md5(''), 0, 16);
        }

        if (mb_strlen($text) <= 48) {
            $slug = Str::slug(mb_substr($text, 0, 48));
            $code = str_replace('-', '_', $slug);

            if ($code !== '' && preg_match('/^[a-z0-9_]+$/', $code) === 1) {
                return mb_substr($code, 0, 80);
            }
        }

        return 'q_'.substr(md5($text), 0, 16);
    }
}
