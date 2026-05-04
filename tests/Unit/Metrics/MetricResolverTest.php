<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\BertScoreLikeMetric;
use Padosoft\EvalHarness\Metrics\CitationGroundednessMetric;
use Padosoft\EvalHarness\Metrics\ContainsMetric;
use Padosoft\EvalHarness\Metrics\CosineEmbeddingMetric;
use Padosoft\EvalHarness\Metrics\ExactMatchMetric;
use Padosoft\EvalHarness\Metrics\LlmAsJudgeMetric;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Metrics\RefusalQualityMetric;
use Padosoft\EvalHarness\Metrics\RegexMetric;
use Padosoft\EvalHarness\Metrics\RougeLMetric;
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

    public function test_alias_resolves_contains(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(ContainsMetric::class, $resolver->resolve('contains'));
    }

    public function test_alias_resolves_regex(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(RegexMetric::class, $resolver->resolve('regex'));
    }

    public function test_alias_resolves_rouge_l(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(RougeLMetric::class, $resolver->resolve('rouge-l'));
    }

    public function test_alias_resolves_citation_groundedness(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(CitationGroundednessMetric::class, $resolver->resolve('citation-groundedness'));
    }

    public function test_alias_resolves_llm_as_judge(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(LlmAsJudgeMetric::class, $resolver->resolve('llm-as-judge'));
    }

    public function test_alias_resolves_bertscore_like(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(BertScoreLikeMetric::class, $resolver->resolve('bertscore-like'));
    }

    public function test_alias_resolves_refusal_quality(): void
    {
        /** @var MetricResolver $resolver */
        $resolver = $this->app->make(MetricResolver::class);
        $this->assertInstanceOf(RefusalQualityMetric::class, $resolver->resolve('refusal-quality'));
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
        $this->assertArrayHasKey('contains', $aliases);
        $this->assertArrayHasKey('regex', $aliases);
        $this->assertArrayHasKey('rouge-l', $aliases);
        $this->assertArrayHasKey('citation-groundedness', $aliases);
        $this->assertArrayHasKey('cosine-embedding', $aliases);
        $this->assertArrayHasKey('bertscore-like', $aliases);
        $this->assertArrayHasKey('llm-as-judge', $aliases);
        $this->assertArrayHasKey('refusal-quality', $aliases);
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
