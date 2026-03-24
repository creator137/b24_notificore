<?php

declare(strict_types=1);

namespace App\Services;

final class TemplateRenderer
{
    public function __construct(
        private readonly array $templates
    ) {}

    public function renderDealStatusMessage(array $deal, array $contact): string
    {
        $name = trim((string)($contact['NAME'] ?? ''));
        $dealTitle = (string)($deal['TITLE'] ?? '');
        $stageId = (string)($deal['STAGE_ID'] ?? '');

        if ($name === '') {
            $name = 'клиент';
        }

        $dealTemplates = $this->templates['deal_stage'] ?? [];
        $template = $dealTemplates[$stageId] ?? ($dealTemplates['default'] ?? '');

        return strtr($template, [
            '{name}' => $name,
            '{deal_title}' => $dealTitle,
            '{stage_id}' => $stageId,
        ]);
    }
}
