<?php

namespace craftyhedge\craftthumbhash\helpers;

class RuleNormalizer
{
    /**
     * @param array<string, mixed> $rawRules
     * @return array<string, array<int, string>>
     */
    public function normalizedRulesByScope(array $rawRules): array
    {
        $normalized = [];

        foreach ($rawRules as $scope => $rawPatterns) {
            $normalizedScope = $this->normalizeScope($scope);
            if ($normalizedScope === null) {
                continue;
            }

            $patterns = is_string($rawPatterns)
                ? [$rawPatterns]
                : (is_array($rawPatterns) ? $rawPatterns : []);

            foreach ($patterns as $rawPattern) {
                if (!is_string($rawPattern)) {
                    continue;
                }

                $normalizedPattern = $this->normalizePattern($rawPattern);
                if ($normalizedPattern === null) {
                    continue;
                }

                $normalized[$normalizedScope][] = $normalizedPattern;
            }
        }

        foreach ($normalized as $scope => $patterns) {
            $unique = array_values(array_unique($patterns));

            if ($unique === []) {
                unset($normalized[$scope]);
                continue;
            }

            $normalized[$scope] = $unique;
        }

        return $normalized;
    }

    public function normalizeScope(string|int $scope): ?string
    {
        if (is_int($scope)) {
            return '*';
        }

        $normalized = trim($scope);
        if ($normalized === '') {
            return null;
        }

        if ($normalized === '*') {
            return '*';
        }

        return strtolower($normalized);
    }

    public function normalizePattern(string $pattern): ?string
    {
        $normalized = trim(str_replace('\\', '/', $pattern));
        if ($normalized === '') {
            return null;
        }

        $normalized = ltrim(preg_replace('#/+#', '/', $normalized) ?? $normalized, '/');
        if ($normalized === '') {
            return null;
        }

        if ($normalized === '*') {
            return '*';
        }

        if (!str_contains($normalized, '*')) {
            $prefix = rtrim($normalized, '/');
            return $prefix === '' ? '*' : "$prefix/*";
        }

        if (str_ends_with($normalized, '/')) {
            return $normalized . '*';
        }

        return $normalized;
    }
}
