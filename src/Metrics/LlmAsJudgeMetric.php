<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Support\MetricUsageDetails;

/**
 * LLM-as-judge metric: ask a model to grade `actual` against
 * `expected` on a 0..1 scale, parse strict-JSON output.
 *
 * Prompt template:
 *   - The default prompt instructs the judge to return JSON of
 *     shape `{"score": <float 0..1>, "reason": "<short string>"}`.
 *     Callers can override `eval-harness.metrics.llm_as_judge.prompt_template`
 *     with their own template; placeholders {expected} {actual}
 *     {question} are interpolated from the sample.
 *
 * Determinism:
 *   - Temperature is forced to 0 in the request body.
 *   - The strict-JSON contract is validated; malformed responses
 *     throw {@see MetricException} so the operator notices instead
 *     of silently scoring 0.
 *
 * Transport is delegated to {@see JudgeClient}. The package binds an
 * OpenAI-compatible HTTP client by default, while tests and host apps
 * can bind deterministic fakes or Laravel AI-backed clients.
 */
final class LlmAsJudgeMetric implements Metric, ProvidesUsageDetails
{
    private const DEFAULT_PROMPT = <<<'PROMPT'
You are a strict evaluator. Given an EXPECTED golden answer and an ACTUAL system answer, output a JSON object {"score": <float 0..1>, "reason": "<one short sentence>"} grading how well the ACTUAL answers the same question as the EXPECTED.

Scoring guide:
- 1.0: equivalent factual content; rewording allowed.
- 0.7–0.9: minor omission or extra detail, no contradiction.
- 0.3–0.6: partial overlap, missing key facts.
- 0.0–0.2: contradicts EXPECTED or is off-topic.

Question: {question}
EXPECTED: {expected}
ACTUAL: {actual}

Return ONLY the JSON object. No prose, no code fences.
PROMPT;

    /**
     * @var array<string, int|float>
     */
    private array $usageDetails = [];

    public function __construct(
        private readonly JudgeClient $judge,
        private readonly ConfigRepository $config,
    ) {}

    public function name(): string
    {
        return 'llm-as-judge';
    }

    public function usageDetails(): array
    {
        return $this->usageDetails;
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $this->usageDetails = [];

        $expected = $this->expectedOutput($sample);
        $question = $this->promptInput($sample);

        $prompt = $this->renderPrompt($expected, $actualOutput, $question);

        try {
            $rawJson = $this->judge->judge($prompt);
        } finally {
            $this->usageDetails = MetricUsageDetails::from($this->judge);
        }

        $decoded = $this->decodeStrictJson($rawJson, $sample->id);

        $rawScore = $decoded['score'];
        if (! is_numeric($rawScore)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' judge response 'score' must be numeric; got %s.",
                    $sample->id,
                    get_debug_type($rawScore),
                ),
            );
        }

        $score = (float) $rawScore;
        if ($score < 0.0 || $score > 1.0 || is_nan($score)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' judge returned out-of-range score %s; expected [0.0, 1.0].",
                    $sample->id,
                    var_export($score, true),
                ),
            );
        }

        $details = MetricUsageDetails::append([
            'judge_score' => $score,
            'judge_reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null,
            'prompt_chars' => strlen($prompt),
            'response_chars' => strlen($rawJson),
        ], $this);

        return new MetricScore(score: $score, details: $details);
    }

    private function expectedOutput(DatasetSample $sample): string
    {
        if (is_string($sample->expectedOutput)) {
            return $sample->expectedOutput;
        }

        try {
            return json_encode($sample->expectedOutput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MetricException(
                sprintf("Sample '%s' expected_output must be JSON-encodable for llm-as-judge metric: %s.", $sample->id, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function promptInput(DatasetSample $sample): string
    {
        if (isset($sample->input['question']) && is_string($sample->input['question']) && $sample->input['question'] !== '') {
            return $sample->input['question'];
        }

        try {
            return json_encode($sample->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MetricException(
                sprintf("Sample '%s' input must be JSON-encodable for llm-as-judge prompt fallback: %s.", $sample->id, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function renderPrompt(string $expected, string $actual, string $question): string
    {
        $rawTemplate = $this->config->get(
            'eval-harness.metrics.llm_as_judge.prompt_template',
            self::DEFAULT_PROMPT,
        );
        $template = is_string($rawTemplate) && $rawTemplate !== ''
            ? $rawTemplate
            : self::DEFAULT_PROMPT;

        return strtr($template, [
            '{expected}' => $expected,
            '{actual}' => $actual,
            '{question}' => $question,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStrictJson(string $raw, string $sampleId): array
    {
        $trimmed = trim($raw);

        // Defensive: strip a single ```json ... ``` fence if a model
        // ignored the response_format hint. We do NOT try to extract
        // JSON from arbitrary prose — that's the operator's signal
        // to fix the prompt or pick a stricter model.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' judge response is not valid JSON: %s. Raw: %s",
                    $sampleId,
                    $e->getMessage(),
                    substr($trimmed, 0, 200),
                ),
                previous: $e,
            );
        }

        if (! is_array($decoded) || ! array_key_exists('score', $decoded)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' judge response missing required 'score' key. Raw: %s",
                    $sampleId,
                    substr($trimmed, 0, 200),
                ),
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
