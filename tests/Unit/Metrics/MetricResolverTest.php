<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\CosineEmbeddingMetric;
use Padosoft\EvalHarness\Metrics\ExactMatchMetric;
use Padosoft\EvalHarness\Metrics\LlmAsJudgeMetric;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Tests\TestCase;

final class MetricResolverTest extends TestCase
{
    public function test_alias_resolves_exact_match(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(ExactMatchMetric::class, $resolver->resolve('exact-match'));
    }

    public function test_alias_resolves_cosine_embedding(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(CosineEmbeddingMetric::class, $resolver->resolve('cosine-embedding'));
    }

    public function test_alias_resolves_llm_as_judge(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(LlmAsJudgeMetric::class, $resolver->resolve('llm-as-judge'));
    }

    public function test_metric_instance_is_passed_through(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $custom = new ExactMatchMetric;
        $this->assertSame($custom, $resolver->resolve($custom));
    }

    public function test_unknown_class_throws(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('does not exist');

        $resolver->resolve('App\\NonExistent\\Metric');
    }

    public function test_class_not_implementing_metric_throws(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('does not implement');

        $resolver->resolve(\stdClass::class);
    }

    public function test_non_string_non_metric_throws(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be a Metric instance, FQCN, or alias string');

        $resolver->resolve(['exact-match']);
    }

    public function test_aliases_static_method_is_complete(): void
    {
        $aliases = MetricResolver::aliases();
        $this->assertArrayHasKey('exact-match', $aliases);
        $this->assertArrayHasKey('cosine-embedding', $aliases);
        $this->assertArrayHasKey('llm-as-judge', $aliases);
    }

    /**
     * Regression: the resolver previously hard-failed on `class_exists`
     * for any non-built-in string, which broke the advertised IoC
     * extension path. Container bindings under an arbitrary string key
     * are now resolved through the container, just like aliases and
     * FQCNs.
     */
    public function test_container_alias_is_resolved_via_make(): void
    {
        $this->app->bind('my-custom-metric', static fn () => new ExactMatchMetric);

        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);

        $this->assertInstanceOf(ExactMatchMetric::class, $resolver->resolve('my-custom-metric'));
    }

    public function test_container_alias_takes_precedence_over_built_in(): void
    {
        // Allow downstream packages to override a built-in by binding
        // their own implementation under the same alias.
        $sentinel = new ExactMatchMetric;
        $this->app->bind('exact-match', static fn () => $sentinel);

        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);

        $this->assertSame($sentinel, $resolver->resolve('exact-match'));
    }
}
