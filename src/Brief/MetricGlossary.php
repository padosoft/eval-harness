<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Brief;

/**
 * What each metric actually measures, in one line.
 *
 * A briefing exists to be read by somebody — or something — that did not write
 * the dataset. "retrieval-mrr: 0.31" is a number; "the first relevant document
 * came back around position 3 on average" is a diagnosis, and it points at the
 * retriever rather than at the prompt.
 *
 * This is the part a briefing built out of pass/fail assertions cannot have:
 * an assertion describes itself, a metric has semantics. Unknown names degrade
 * to null and the row simply prints its score, so a host's own metric is never
 * misdescribed by a guess.
 */
final class MetricGlossary
{
    private const GLOSSARY = [
        'exact-match' => 'exact string equality with the expected answer — 1.0 or 0.0, no partial credit.',
        'contains' => 'the expected string appears somewhere in the answer.',
        'regex' => 'the answer matches the configured pattern.',
        'rouge-l' => 'longest-common-subsequence overlap with the expected answer; low means the wording diverged, not necessarily that the meaning did.',
        'citation-groundedness' => 'the answer carries the citation markers (and, in strict mode, the quoted spans) the sample declared; low means it asserted something it did not source.',
        'cosine-embedding' => 'cosine similarity between the answer and the expected answer in embedding space; tolerant of paraphrase, blind to a confidently wrong fact stated in the right style.',
        'bertscore-like' => 'token-level semantic overlap via embeddings; a middling score usually means partially right rather than differently worded.',
        'llm-as-judge' => 'a model graded the answer against the rubric; read `judge_reason` before anything else, it is the only field that says why.',
        'refusal-quality' => 'whether the answer refused when it should have (or answered when it should have); a failure here is a safety behaviour, not a wording problem.',
        'ordinal-distance' => 'distance on an ordered scale — 1.0 exact, 0.5 off by one, 0.0 further; a 0.5 means the model was close, not wrong in a random direction.',
        'retrieval-hit-at-k' => 'at least one relevant document appeared in the top k; 0.0 means retrieval never surfaced the answer, so the generator never had a chance.',
        'retrieval-recall-at-k' => 'the fraction of relevant documents that made the top k.',
        'retrieval-mrr' => 'reciprocal rank of the first relevant document; 0.5 means it was second on average, 0.33 third.',
        'retrieval-ndcg-at-k' => 'ranking quality with position discounting; falls when relevant documents are present but buried.',
        'answer-containment-at-k' => 'the expected answer span appears in the top-k retrieved texts; separates "retrieval missed it" from "the generator ignored it".',
        'tool-called' => 'the agent called the tools this row required; 0.0 means it answered without looking anything up — a guess, whether or not it landed.',
        'tool-not-called' => 'the agent stayed away from tools it must not use here; a failure is an action taken when only an answer was wanted.',
        'tool-called-with' => 'the tool was called with the arguments this row expected; a failure often means it looked up the wrong record confidently.',
        'tool-call-order' => 'the tools happened in a defensible order (checking stock before charging, not after).',
        'steps-below' => 'the agent stayed inside its step budget; agents get expensive before they get wrong.',
        'no-pending-approvals' => 'the run finished rather than stopping on an approval; text that says "I have submitted that" while an approval is pending reads as success and is not.',
        'approval-gated' => 'the actions that needed approval got it; an unapproved action is a compliance finding, not a UX detail.',
    ];

    public static function describe(string $metric): ?string
    {
        return self::GLOSSARY[$metric] ?? null;
    }

    /**
     * @param  list<string>  $metrics
     * @return array<string, string>
     */
    public static function for(array $metrics): array
    {
        $described = [];

        foreach ($metrics as $metric) {
            $description = self::describe($metric);

            if ($description !== null) {
                $described[$metric] = $description;
            }
        }

        return $described;
    }
}
