<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Support;

use Padosoft\EvalHarness\Support\ProviderUsageDetails;
use PHPUnit\Framework\TestCase;

/**
 * The model name is what turns token counts into money, so it has to survive
 * the trip from the response body into the metric's details.
 */
final class ProviderUsageModelTest extends TestCase
{
    public function test_the_model_is_read_from_the_top_level_of_the_response(): void
    {
        $details = ProviderUsageDetails::fromResponseBody([
            'model' => 'gpt-4o-mini-2024-07-18',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 8],
        ]);

        $this->assertSame('gpt-4o-mini-2024-07-18', $details['model']);
        $this->assertSame(120, $details['prompt_tokens']);
    }

    public function test_a_missing_model_leaves_the_key_absent_rather_than_empty(): void
    {
        $details = ProviderUsageDetails::fromResponseBody(['usage' => ['prompt_tokens' => 1]]);

        $this->assertArrayNotHasKey('model', $details);
    }

    public function test_a_blank_model_is_not_recorded(): void
    {
        $details = ProviderUsageDetails::fromResponseBody(['model' => '   ', 'usage' => ['prompt_tokens' => 1]]);

        $this->assertArrayNotHasKey('model', $details);
    }

    public function test_a_non_string_model_is_ignored(): void
    {
        $details = ProviderUsageDetails::fromResponseBody(['model' => ['gpt-4o'], 'usage' => ['prompt_tokens' => 1]]);

        $this->assertArrayNotHasKey('model', $details);
    }
}
