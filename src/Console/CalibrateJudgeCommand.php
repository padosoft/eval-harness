<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;
use Padosoft\EvalHarness\Calibration\CalibrationCaseLoader;
use Padosoft\EvalHarness\Calibration\JudgeCalibrationReport;
use Padosoft\EvalHarness\Calibration\JudgeCalibrator;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Support\RuntimeOptions;

/**
 * Artisan entry point: `php artisan eval-harness:calibrate-judge <cases>`.
 *
 * Validates the configured LLM judge against human-labelled cases and
 * gates on the verdict agreement rate. Fails (exit 1) when agreement
 * falls below the floor OR a self-preference violation is detected;
 * emits a non-fatal warning when length bias is suspected.
 *
 * Output mirrors `eval-harness:run`: Markdown by default, `--json` for
 * the machine-readable envelope, `--out`/`--raw-path` to write to disk.
 */
final class CalibrateJudgeCommand extends Command
{
    use WritesEvalReports;

    public const SCHEMA = 'eval-harness.calibration.report.v1';

    /** @var string */
    protected $signature = 'eval-harness:calibrate-judge
        {cases : Path to a calibration YAML file (human-labelled cases)}
        {--min-agreement= : Minimum verdict agreement rate to pass (0..1); overrides config}
        {--model-under-test= : Model name under test, for the self-preference guard}
        {--json : Emit a JSON calibration report instead of Markdown}
        {--out= : Write the report to this path (relative paths use the reports disk + prefix unless --raw-path)}
        {--raw-path : Treat --out as a literal cwd-relative path}';

    /** @var string */
    protected $description = 'Validate the LLM judge against human labels (verdict agreement, length-bias and self-preference guards).';

    public function handle(JudgeCalibrator $calibrator, CalibrationCaseLoader $loader, ConfigRepository $config): int
    {
        $casesPath = (string) $this->argument('cases');

        try {
            $cases = $loader->loadFile($casesPath);
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $judgeModel = $this->stringConfig($config, 'eval-harness.metrics.llm_as_judge.model');
        $modelUnderTest = $this->resolveModelUnderTest($config);

        try {
            $report = $calibrator->run($cases, judgeModel: $judgeModel, modelUnderTest: $modelUnderTest);
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = $this->renderPayload($report);
        if ($payload === null) {
            return self::FAILURE;
        }

        if (! $this->writeOrPrintReport($payload)) {
            return self::FAILURE;
        }

        // When the machine-readable JSON is emitted to stdout (no --out),
        // suppress the human diagnostic lines so the advertised JSON
        // payload stays parseable; the same signals are already encoded
        // in the JSON (agreement_rate, self_preference_violation, ...).
        $out = $this->option('out');
        $jsonToStdout = (bool) $this->option('json') && ! (is_string($out) && $out !== '');

        return $this->finalize($report, $config, $jsonToStdout);
    }

    private function renderPayload(JudgeCalibrationReport $report): ?string
    {
        if (! $this->option('json')) {
            return $this->renderMarkdown($report);
        }

        try {
            $payload = json_encode([
                'schema_version' => self::SCHEMA,
                'data' => $report->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error(sprintf('Failed to encode calibration report as JSON: %s.', $e->getMessage()));

            return null;
        }

        return $payload;
    }

    private function renderMarkdown(JudgeCalibrationReport $report): string
    {
        $confusion = $report->confusion();

        $lines = [
            '# Judge calibration report',
            '',
            sprintf('- Cases: %d', $report->caseCount()),
            sprintf('- Agreement rate: %.4f', $report->agreementRate()),
            sprintf('- Verdict pass threshold: %.4f', $report->toArray()['verdict_pass_threshold']),
            sprintf('- Length-bias correlation: %.4f', $report->lengthBiasCorrelation()),
            sprintf('- Self-preference violation: %s', $report->selfPreferenceViolation() ? 'yes' : 'no'),
            '',
            '| true_pass | true_fail | false_pass | false_fail |',
            '| --- | --- | --- | --- |',
            sprintf(
                '| %d | %d | %d | %d |',
                $confusion['true_pass'],
                $confusion['true_fail'],
                $confusion['false_pass'],
                $confusion['false_fail'],
            ),
        ];

        return implode("\n", $lines);
    }

    private function finalize(JudgeCalibrationReport $report, ConfigRepository $config, bool $suppressDiagnostics): int
    {
        $lengthBiasWarn = RuntimeOptions::normalizeUnitInterval(
            $config->get('eval-harness.calibration.length_bias_warn'),
            0.4,
        );
        if (! $suppressDiagnostics && $report->lengthBiasWarned($lengthBiasWarn)) {
            $this->warn(sprintf(
                'Length-bias warning: judge score correlates with answer length (rank correlation %.4f >= %.4f).',
                $report->lengthBiasCorrelation(),
                $lengthBiasWarn,
            ));
        }

        if ($report->selfPreferenceViolation()) {
            if (! $suppressDiagnostics) {
                $this->error('Self-preference violation: the judge model equals the model under test.');
            }

            return self::FAILURE;
        }

        $minAgreement = $this->resolveMinAgreement($config);
        if (! $report->meetsThreshold($minAgreement)) {
            if (! $suppressDiagnostics) {
                $this->error(sprintf(
                    'Verdict agreement %.4f is below the required minimum %.4f.',
                    $report->agreementRate(),
                    $minAgreement,
                ));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveMinAgreement(ConfigRepository $config): float
    {
        $option = $this->option('min-agreement');
        if (is_string($option) && trim($option) !== '') {
            return RuntimeOptions::normalizeUnitInterval($option, $this->configMinAgreement($config));
        }

        return $this->configMinAgreement($config);
    }

    private function configMinAgreement(ConfigRepository $config): float
    {
        return RuntimeOptions::normalizeUnitInterval(
            $config->get('eval-harness.calibration.min_agreement'),
            0.8,
        );
    }

    private function resolveModelUnderTest(ConfigRepository $config): ?string
    {
        $option = $this->option('model-under-test');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return $this->stringConfig($config, 'eval-harness.calibration.model_under_test');
    }

    private function stringConfig(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
