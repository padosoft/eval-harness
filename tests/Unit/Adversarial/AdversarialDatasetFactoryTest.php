<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Adversarial;

use Padosoft\EvalHarness\Adversarial\AdversarialCategory;
use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Tests\TestCase;

final class AdversarialDatasetFactoryTest extends TestCase
{
    public function test_default_dataset_contains_all_opt_in_seed_categories(): void
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        $dataset = $factory->build();

        $this->assertSame(AdversarialDatasetFactory::DEFAULT_DATASET_NAME, $dataset->name);
        $this->assertSame(['refusal-quality'], $dataset->metricNames());
        $this->assertCount(10, $dataset->samples);
        $this->assertSame(
            array_map(static fn (AdversarialCategory $category): string => 'adv.'.$category->value, AdversarialCategory::defaults()),
            array_map(static fn ($sample): string => $sample->id, $dataset->samples),
        );

        foreach ($dataset->samples as $sample) {
            $this->assertTrue($sample->metadata['refusal_expected']);
            $this->assertContains('adversarial', $sample->metadata['tags']);
            $this->assertSame($sample->metadata['adversarial']['category'], $sample->metadata['tags'][1]);
            $this->assertNotSame([], $sample->metadata['adversarial']['compliance_frameworks']);
            $this->assertIsString($sample->expectedOutput);
            $this->assertNotSame('', $sample->expectedOutput);
        }
    }

    public function test_selected_categories_keep_stable_metadata_and_multi_input_shape(): void
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        $samples = $factory->samples([
            AdversarialCategory::PromptInjection,
            'tool-abuse',
        ]);

        $this->assertCount(2, $samples);
        $this->assertSame('adv.prompt-injection', $samples[0]->id);
        $this->assertSame('prompt-injection', $samples[0]->metadata['adversarial']['category']);
        $this->assertArrayHasKey('retrieved_context', $samples[0]->input);
        $this->assertSame('adv.tool-abuse', $samples[1]->id);
        $this->assertArrayHasKey('tool_request', $samples[1]->input);
    }

    public function test_invalid_category_selection_fails_closed(): void
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Unsupported adversarial category 'unknown'");

        $factory->samples(['unknown']);
    }

    public function test_duplicate_category_selection_fails_closed(): void
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Duplicate adversarial category 'jailbreak'");

        $factory->samples(['jailbreak', AdversarialCategory::Jailbreak]);
    }

    public function test_build_rejects_empty_metric_list(): void
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('requires at least one metric');

        $factory->build(metricSpecs: []);
    }
}
