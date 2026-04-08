<?php

namespace craftyhedge\craftthumbhash\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftthumbhash\helpers\RuleNormalizer;

class RuleNormalizerTest extends Unit
{
    private RuleNormalizer $normalizer;

    protected function _before(): void
    {
        $this->normalizer = new RuleNormalizer();
    }

    // ── normalizeScope ──────────────────────────────────────────────

    public function testNormalizeScopeWildcardPassthrough(): void
    {
        $this->assertSame('*', $this->normalizer->normalizeScope('*'));
    }

    public function testNormalizeScopeIntegerKeyReturnsStar(): void
    {
        $this->assertSame('*', $this->normalizer->normalizeScope(0));
        $this->assertSame('*', $this->normalizer->normalizeScope(42));
    }

    public function testNormalizeScopeTrimsWhitespace(): void
    {
        $this->assertSame('uploads', $this->normalizer->normalizeScope('  uploads  '));
    }

    public function testNormalizeScopeLowercases(): void
    {
        $this->assertSame('uploads', $this->normalizer->normalizeScope('Uploads'));
        $this->assertSame('my-volume', $this->normalizer->normalizeScope('My-Volume'));
    }

    public function testNormalizeScopeEmptyReturnsNull(): void
    {
        $this->assertNull($this->normalizer->normalizeScope(''));
        $this->assertNull($this->normalizer->normalizeScope('   '));
    }

    // ── normalizePattern ────────────────────────────────────────────

    public function testNormalizePatternWildcardPassthrough(): void
    {
        $this->assertSame('*', $this->normalizer->normalizePattern('*'));
    }

    public function testNormalizePatternTrailingSlashGetsWildcard(): void
    {
        $this->assertSame('photos/*', $this->normalizer->normalizePattern('photos/'));
    }

    public function testNormalizePatternBackslashNormalization(): void
    {
        $this->assertSame('photos/vacation/*', $this->normalizer->normalizePattern('photos\\vacation\\'));
    }

    public function testNormalizePatternNoWildcardGetsSuffix(): void
    {
        $this->assertSame('photos/*', $this->normalizer->normalizePattern('photos'));
        $this->assertSame('photos/vacation/*', $this->normalizer->normalizePattern('photos/vacation'));
    }

    public function testNormalizePatternExistingWildcardPreserved(): void
    {
        $this->assertSame('photos/*/thumbs', $this->normalizer->normalizePattern('photos/*/thumbs'));
        $this->assertSame('photos/*', $this->normalizer->normalizePattern('photos/*'));
    }

    public function testNormalizePatternEmptyReturnsNull(): void
    {
        $this->assertNull($this->normalizer->normalizePattern(''));
        $this->assertNull($this->normalizer->normalizePattern('   '));
    }

    public function testNormalizePatternLeadingSlashStripped(): void
    {
        $this->assertSame('photos/*', $this->normalizer->normalizePattern('/photos'));
    }

    public function testNormalizePatternDuplicateSlashesCollapsed(): void
    {
        $this->assertSame('photos/vacation/*', $this->normalizer->normalizePattern('photos///vacation'));
    }

    public function testNormalizePatternOnlySlashesReturnsNull(): void
    {
        $this->assertNull($this->normalizer->normalizePattern('/'));
        $this->assertNull($this->normalizer->normalizePattern('///'));
    }

    // ── normalizedRulesByScope ──────────────────────────────────────

    public function testNormalizedRulesByScopeEmptyInput(): void
    {
        $this->assertSame([], $this->normalizer->normalizedRulesByScope([]));
    }

    public function testNormalizedRulesByScopeBasicScoping(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            'uploads' => 'photos',
            '*' => ['archive/*'],
        ]);

        $this->assertSame([
            'uploads' => ['photos/*'],
            '*' => ['archive/*'],
        ], $result);
    }

    public function testNormalizedRulesByScopeIntegerKeysBecomeStar(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            0 => 'temp/*',
            1 => 'cache/*',
        ]);

        $this->assertSame([
            '*' => ['temp/*', 'cache/*'],
        ], $result);
    }

    public function testNormalizedRulesByScopeDeduplication(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            'uploads' => ['photos/*', 'photos/*', 'docs/*'],
        ]);

        $this->assertSame([
            'uploads' => ['photos/*', 'docs/*'],
        ], $result);
    }

    public function testNormalizedRulesByScopeInvalidEntriesDropped(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            '' => 'something',
            'uploads' => [123, null, 'photos'],
        ]);

        $this->assertSame([
            'uploads' => ['photos/*'],
        ], $result);
    }

    public function testNormalizedRulesByScopeEmptyScopeDropped(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            'uploads' => ['', '  '],
        ]);

        $this->assertSame([], $result);
    }

    public function testNormalizedRulesByScopeStringPatternWrappedInArray(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            'uploads' => 'photos',
        ]);

        $this->assertSame([
            'uploads' => ['photos/*'],
        ], $result);
    }

    public function testNormalizedRulesByScopeNonArrayNonStringPatternsDropped(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            'uploads' => 42,
            'media' => true,
        ]);

        $this->assertSame([], $result);
    }

    public function testNormalizedRulesByScopeMixedScopeTypes(): void
    {
        $result = $this->normalizer->normalizedRulesByScope([
            '*' => ['global/*'],
            'Uploads' => ['photos'],
            0 => 'temp/*',
            '' => 'dropped',
        ]);

        $this->assertSame([
            '*' => ['global/*', 'temp/*'],
            'uploads' => ['photos/*'],
        ], $result);
    }
}
