<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;

enum AdversarialCategory: string
{
    case PromptInjection = 'prompt-injection';
    case Jailbreak = 'jailbreak';
    case ToolAbuse = 'tool-abuse';
    case PiiLeak = 'pii-leak';
    case Ssrf = 'ssrf';
    case SqlShellInjection = 'sql-shell-injection';
    case AsciiSmuggling = 'ascii-smuggling';
    case CompetitorEndorsement = 'competitor-endorsement';
    case ExcessiveAgency = 'excessive-agency';
    case HallucinationOverreliance = 'hallucination-overreliance';

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [
            self::PromptInjection,
            self::Jailbreak,
            self::ToolAbuse,
            self::PiiLeak,
            self::Ssrf,
            self::SqlShellInjection,
            self::AsciiSmuggling,
            self::CompetitorEndorsement,
            self::ExcessiveAgency,
            self::HallucinationOverreliance,
        ];
    }

    public static function fromName(string $name): self
    {
        $category = self::tryFrom($name);
        if ($category instanceof self) {
            return $category;
        }

        throw new DatasetSchemaException(sprintf(
            "Unsupported adversarial category '%s'. Supported categories: %s.",
            $name,
            implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::PromptInjection => 'Prompt injection',
            self::Jailbreak => 'Jailbreak',
            self::ToolAbuse => 'Tool abuse',
            self::PiiLeak => 'PII leak',
            self::Ssrf => 'SSRF',
            self::SqlShellInjection => 'SQL/shell injection',
            self::AsciiSmuggling => 'ASCII smuggling',
            self::CompetitorEndorsement => 'Competitor endorsement',
            self::ExcessiveAgency => 'Excessive agency',
            self::HallucinationOverreliance => 'Hallucination overreliance',
        };
    }

    /**
     * @return list<string>
     */
    public function complianceFrameworks(): array
    {
        return match ($this) {
            self::PromptInjection,
            self::Jailbreak,
            self::AsciiSmuggling => ['OWASP LLM', 'NIST AI RMF'],
            self::ToolAbuse,
            self::Ssrf,
            self::SqlShellInjection,
            self::ExcessiveAgency => ['OWASP LLM'],
            self::PiiLeak => ['OWASP LLM', 'NIST AI RMF', 'EU AI Act'],
            self::CompetitorEndorsement,
            self::HallucinationOverreliance => ['NIST AI RMF'],
        };
    }
}
