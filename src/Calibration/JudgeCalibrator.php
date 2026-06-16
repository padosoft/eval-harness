<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Calibration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\LlmAsJudgeMetric;
use Padosoft\EvalHarness\Support\RuntimeOptions;

/**
 * Validates the configured {@see JudgeClient} against human-labelled
 * cases: it judges each case, converts the raw score into a pass/fail
 * verdict via the configured `verdict_pass_threshold`, and measures the
 * agreement rate of verdicts against the human ground truth.
 *
 * It also computes a length-bias signal (Spearman rank correlation
 * between answer length and judge score) and a self-preference guard
 * (judge model == model under test).
 *
 * The judge prompt and strict-JSON decoding mirror
 * {@see LlmAsJudgeMetric} without
 * modifying it, keeping that metric stable.
 */
final class JudgeCalibrator
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

    public function __construct(
        private readonly JudgeClient $judge,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @param  list<HumanLabel>  $cases
     */
    public function run(array $cases, ?string $judgeModel = null, ?string $modelUnderTest = null): JudgeCalibrationReport
    {
        $threshold = $this->verdictPassThreshold();

        $matches = 0;
        $confusion = ['true_pass' => 0, 'true_fail' => 0, 'false_pass' => 0, 'false_fail' => 0];
        $lengths = [];
        $scores = [];

        foreach ($cases as $case) {
            $score = $this->scoreCase($case);
            $verdict = $score >= $threshold ? 'pass' : 'fail';

            if ($verdict === $case->humanVerdict) {
                $matches++;
            }

            $this->recordConfusion($confusion, $case->humanVerdict, $verdict);

            $lengths[] = (float) mb_strlen($case->actual, 'UTF-8');
            $scores[] = $score;
        }

        $caseCount = count($cases);
        $agreement = $caseCount > 0 ? $matches / $caseCount : 0.0;

        return new JudgeCalibrationReport(
            agreementRate: $agreement,
            confusion: $confusion,
            lengthBiasCorrelation: $this->spearman($lengths, $scores),
            selfPreferenceViolation: $this->isSelfPreferenceViolation($judgeModel, $modelUnderTest),
            caseCount: $caseCount,
            verdictPassThreshold: $threshold,
        );
    }

    private function scoreCase(HumanLabel $case): float
    {
        $question = $this->questionFor($case);
        $prompt = strtr($this->promptTemplate(), [
            '{expected}' => $case->expected,
            '{actual}' => $case->actual,
            '{question}' => $question,
        ]);

        $decoded = $this->decodeStrictJson($this->judge->judge($prompt), $case->id);

        $rawScore = $decoded['score'] ?? null;
        if (! is_numeric($rawScore)) {
            throw new MetricException(sprintf(
                "Calibration case '%s' judge response 'score' must be numeric; got %s.",
                $case->id,
                get_debug_type($rawScore),
            ));
        }

        $score = (float) $rawScore;
        if ($score < 0.0 || $score > 1.0 || is_nan($score)) {
            throw new MetricException(sprintf(
                "Calibration case '%s' judge returned out-of-range score %s; expected [0.0, 1.0].",
                $case->id,
                var_export($score, true),
            ));
        }

        return $score;
    }

    private function questionFor(HumanLabel $case): string
    {
        $question = $case->input['question'] ?? null;
        if (is_string($question) && $question !== '') {
            return $question;
        }

        try {
            return json_encode($case->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MetricException(
                sprintf("Calibration case '%s' input must be JSON-encodable for the judge prompt: %s.", $case->id, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function promptTemplate(): string
    {
        $raw = $this->config->get('eval-harness.metrics.llm_as_judge.prompt_template', self::DEFAULT_PROMPT);

        return is_string($raw) && $raw !== '' ? $raw : self::DEFAULT_PROMPT;
    }

    /**
     * @param  array{true_pass: int, true_fail: int, false_pass: int, false_fail: int}  $confusion
     */
    private function recordConfusion(array &$confusion, string $humanVerdict, string $judgeVerdict): void
    {
        $key = match (true) {
            $humanVerdict === 'pass' && $judgeVerdict === 'pass' => 'true_pass',
            $humanVerdict === 'fail' && $judgeVerdict === 'fail' => 'true_fail',
            $humanVerdict === 'fail' && $judgeVerdict === 'pass' => 'false_pass',
            default => 'false_fail',
        };

        $confusion[$key]++;
    }

    private function isSelfPreferenceViolation(?string $judgeModel, ?string $modelUnderTest): bool
    {
        $requireDistinct = RuntimeOptions::normalizeBoolean(
            $this->config->get('eval-harness.calibration.require_distinct_models'),
            true,
        );

        if (! $requireDistinct) {
            return false;
        }

        return $judgeModel !== null && $judgeModel === $modelUnderTest;
    }

    private function verdictPassThreshold(): float
    {
        return RuntimeOptions::normalizeUnitInterval(
            $this->config->get('eval-harness.calibration.verdict_pass_threshold'),
            0.5,
        );
    }

    /**
     * Spearman rank correlation between two equally-sized series.
     * Returns 0.0 when fewer than two points or when either series has
     * zero variance (no rank dispersion to correlate).
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function spearman(array $a, array $b): float
    {
        $n = count($a);
        if ($n < 2) {
            return 0.0;
        }

        $rankA = $this->averageRanks($a);
        $rankB = $this->averageRanks($b);

        $meanA = array_sum($rankA) / $n;
        $meanB = array_sum($rankB) / $n;

        $cov = 0.0;
        $varA = 0.0;
        $varB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $rankA[$i] - $meanA;
            $db = $rankB[$i] - $meanB;
            $cov += $da * $db;
            $varA += $da * $da;
            $varB += $db * $db;
        }

        if ($varA <= 0.0 || $varB <= 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $cov / sqrt($varA * $varB)));
    }

    /**
     * Fractional (tie-averaged) ranks for a series.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    private function averageRanks(array $values): array
    {
        $sorted = $values;
        sort($sorted);

        /** @var array<string, float> $rankByValue */
        $rankByValue = [];
        $i = 0;
        $n = count($sorted);
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $sorted[$j + 1] === $sorted[$i]) {
                $j++;
            }
            // 1-based average rank for the tie group [$i, $j].
            $averageRank = (($i + 1) + ($j + 1)) / 2.0;
            $rankByValue[$this->key($sorted[$i])] = $averageRank;
            $i = $j + 1;
        }

        return array_map(fn (float $value): float => $rankByValue[$this->key($value)], $values);
    }

    private function key(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStrictJson(string $raw, string $caseId): array
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MetricException(
                sprintf("Calibration case '%s' judge response is not valid JSON: %s.", $caseId, $e->getMessage()),
                previous: $e,
            );
        }

        if (! is_array($decoded) || ! array_key_exists('score', $decoded)) {
            throw new MetricException(
                sprintf("Calibration case '%s' judge response missing required 'score' key.", $caseId),
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
