<?php
/**
 * Test-Honesty Analyzer
 *
 * Scans PHP test files and evaluates test quality across 6 assertion categories:
 * A. Business Logic Validation
 * B. State Isolation / Side Effects
 * C. Error Semantics
 * D. Data Integrity / Relationships
 * E. Idempotency / Concurrency
 * F. Boundary / Edge Cases
 *
 * Score = (categories_touched / 6) × 100
 * Warns if score < 50%
 */

class TestHonestyAnalyzer
{
    private $results = [];
    private $totalTests = 0;
    private $hollowTests = [];
    private $honestTests = [];

    // Assertion patterns for each category
    private $patterns = [
        'A' => [
            'assertDatabaseHas',
            'assertDatabaseMissing',
            'assertSame.*state',
            'assertSame.*\$invoice',
            'assertSame.*\$payment',
            'assertSame.*\$client',
            'assertEquals.*balance',
            'assertTrue.*paid',
            'assertFalse.*pending',
        ],
        'B' => [
            'assertDatabaseMissing',
            'assertDatabaseCount',
            'spy.*',
            'Mock.*verify',
            '\$countBefore.*\$countAfter',
            'assertSame.*\$before.*\$after',
        ],
        'C' => [
            'assertResponseStatusCode',
            'assertResponseStatus',
            'assertStatus',
            'assertResponseBodyContains',
            'assertResponseBodyNotContains',
            'assertStringContains.*response',
            'assertStringNotContains.*response',
            'assertEquals.*404',
            'assertEquals.*403',
            'assertEquals.*201',
        ],
        'D' => [
            'assertDatabaseRow',
            'assertDatabaseHas',
            'assertSame.*\$.*->.*_id',
            'assertSame.*\$.*client_id',
            'assertSame.*\$.*invoice_id',
            'assertSame.*\$.*payment_id',
            'assertEquals.*relationship',
        ],
        'E' => [
            'repeated request',
            'twice',
            'idempotent',
            'race',
            'concurrent',
            'transaction',
            'rollback',
            'commit',
            'second.*post',
            'second.*request',
        ],
        'F' => [
            '0|zero',
            '-1|negative',
            '99999|nonexistent',
            'null|empty string',
            'boundary',
            'edge case',
            'invalid.*id',
            'non-numeric',
        ],
    ];

    public function analyzeDirectory($directory)
    {
        $files = $this->findTestFiles($directory);

        foreach ($files as $file) {
            $this->_analyzeFile($file);
        }

        return $this->generateReport();
    }

    public function analyzeFile($filePath)
    {
        $this->_analyzeFile($filePath);
        return $this->generateReport();
    }

    private function findTestFiles($directory)
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && strpos($file->getFilename(), 'Test.php') !== false) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    private function _analyzeFile($filePath)
    {
        $content = file_get_contents($filePath);
        $relPath = str_replace(getcwd() . '/', '', $filePath);

        // Extract test methods (handles return type hints and attributes)
        $pattern = '/(?:#\[.*?\])?\s*public\s+function\s+(it_[a-z0-9_]+)\s*\([^)]*\)(?:\s*:\s*\w+)??\s*\{/';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $testName = $matches[1][$i][0];
                $startPos = $matches[0][$i][1];

                // Find the matching closing brace
                $braceCount = 0;
                $inString = false;
                $stringChar = '';
                $testBody = '';

                // Start from the opening brace
                for ($pos = strpos($content, '{', $startPos); $pos < strlen($content); $pos++) {
                    $char = $content[$pos];

                    // Handle strings
                    if ($char === '"' || $char === "'") {
                        if (!$inString) {
                            $inString = true;
                            $stringChar = $char;
                        } elseif ($char === $stringChar && ($pos === 0 || $content[$pos - 1] !== '\\')) {
                            $inString = false;
                        }
                    }

                    if (!$inString) {
                        if ($char === '{') {
                            $braceCount++;
                        } elseif ($char === '}') {
                            $braceCount--;
                            if ($braceCount === 0) {
                                break;
                            }
                        }
                    }

                    $testBody .= $char;
                }

                $analysis = $this->analyzeTest($testName, $testBody);
                $this->results[$relPath . '::' . $testName] = [
                    'file' => $relPath,
                    'method' => $testName,
                    'analysis' => $analysis,
                ];

                $this->totalTests++;

                if ($analysis['score'] < 50) {
                    $this->hollowTests[] = $testName;
                } else {
                    $this->honestTests[] = $testName;
                }
            }
        }
    }

    private function analyzeTest($testName, $testBody)
    {
        $assertions = $this->extractAssertions($testBody);
        $categoriesUsed = [];

        // Determine which categories are represented
        foreach (array_keys($this->patterns) as $category) {
            foreach ($this->patterns[$category] as $pattern) {
                if ($this->matchesPattern($assertions, $pattern)) {
                    $categoriesUsed[] = $category;
                    break;
                }
            }
        }

        $categoriesUsed = array_unique($categoriesUsed);
        $score = (count($categoriesUsed) / 6) * 100;

        return [
            'assertions' => count($assertions),
            'categories_used' => $categoriesUsed,
            'categories_count' => count($categoriesUsed),
            'score' => round($score, 1),
            'is_hollow' => $score < 50,
            'assertion_list' => $assertions,
        ];
    }

    private function extractAssertions($testBody)
    {
        $assertions = [];

        // Match all assertion calls - simpler pattern
        if (preg_match_all('/\$this->assert\w+/', $testBody, $matches)) {
            $assertions = array_unique($matches[0]);
        }

        // Look for specific assertion patterns
        if (preg_match('/assertResponseStatusCode/', $testBody)) {
            $assertions[] = 'assertResponseStatusCode';
        }
        if (preg_match('/assertResponseBodyContains/', $testBody)) {
            $assertions[] = 'assertResponseBodyContains';
        }
        if (preg_match('/assertResponseBodyNotContains/', $testBody)) {
            $assertions[] = 'assertResponseBodyNotContains';
        }
        if (preg_match('/assertDatabaseHas/', $testBody)) {
            $assertions[] = 'assertDatabaseHas';
        }
        if (preg_match('/assertDatabaseMissing/', $testBody)) {
            $assertions[] = 'assertDatabaseMissing';
        }
        if (preg_match('/assertDatabaseRow/', $testBody)) {
            $assertions[] = 'assertDatabaseRow';
        }
        if (preg_match('/assertDatabaseCount/', $testBody)) {
            $assertions[] = 'assertDatabaseCount';
        }
        if (preg_match('/assertSame|assertEquals/', $testBody)) {
            $assertions[] = 'assertSame/assertEquals';
        }
        if (preg_match('/assertTrue|assertFalse/', $testBody)) {
            $assertions[] = 'assertTrue/assertFalse';
        }

        // Also look for state comparisons (pre/post)
        if (preg_match('/\$.*Before|countBefore|\$.*Before\s*=/', $testBody) &&
            preg_match('/\$.*After|countAfter|\$.*After\s*=/', $testBody)) {
            $assertions[] = '(pre/post state comparison)';
        }

        // Check for repeated requests
        if (preg_match('/\$response\s*=.*\$response2|second.*request|POST.*POST/is', $testBody)) {
            $assertions[] = '(repeated request)';
        }

        return array_unique(array_filter($assertions));
    }

    private function matchesPattern($assertions, $pattern)
    {
        $allText = implode(' ', $assertions);
        $normalizedPattern = str_replace('|', '|', $pattern);

        return (bool) preg_match('/' . preg_quote($pattern, '/') . '/i', $allText) ||
               preg_match('/' . $pattern . '/i', $allText);
    }

    private function generateReport()
    {
        $report = [];
        $report[] = "# Test-Honesty Analysis Report";
        $report[] = "";
        $report[] = "## Summary";
        $report[] = sprintf("- **Total Tests Analyzed**: %d", $this->totalTests);
        $report[] = sprintf("- **Hollow Tests** (score < 50%%): %d", count($this->hollowTests));
        $report[] = sprintf("- **Honest Tests** (score ≥ 50%%): %d", count($this->honestTests));
        $report[] = sprintf("- **Hollow Ratio**: %.1f%%", ($this->totalTests > 0) ? (count($this->hollowTests) / $this->totalTests * 100) : 0);
        $report[] = "";
        $report[] = "## Detailed Results";
        $report[] = "";

        // Group by file
        $fileGroups = [];
        foreach ($this->results as $testId => $result) {
            $file = $result['file'];
            if (!isset($fileGroups[$file])) {
                $fileGroups[$file] = [];
            }
            $fileGroups[$file][] = $result;
        }

        foreach ($fileGroups as $file => $tests) {
            $report[] = sprintf("### %s", $file);
            $report[] = "";

            foreach ($tests as $result) {
                $analysis = $result['analysis'];
                $hollow = $analysis['is_hollow'] ? ' ⚠️ HOLLOW' : ' ✓ HONEST';
                $report[] = sprintf("#### %s%s", $result['method'], $hollow);
                $report[] = sprintf("- **Score**: %.1f%% (%d/%d categories)",
                    $analysis['score'],
                    $analysis['categories_count'],
                    6
                );
                $report[] = sprintf("- **Categories Used**: %s",
                    implode(', ', $analysis['categories_used']) ?: 'None'
                );
                $report[] = sprintf("- **Assertions**: %d", $analysis['assertions']);
                $report[] = "";

                if ($analysis['is_hollow']) {
                    $report[] = "**⚠️ Improvement Suggestions:**";
                    $report[] = "";

                    $missing = $this->suggestMissingCategories($analysis['categories_used']);
                    foreach ($missing as $category => $suggestion) {
                        $report[] = sprintf("- **Add %s (Category %s)**: %s",
                            $category,
                            array_search($category, ['A', 'B', 'C', 'D', 'E', 'F']),
                            $suggestion
                        );
                    }
                    $report[] = "";
                }
            }
        }

        $report[] = "## Interpretation";
        $report[] = "";
        $report[] = "**Score < 50%**: Test is **hollow** — checks response properties only, doesn't verify business logic.";
        $report[] = "";
        $report[] = "**Score ≥ 50%**: Test is **honest** — touches 3+ assertion categories, verifies logic + state.";
        $report[] = "";
        $report[] = "### Categories:";
        $report[] = "- **A**: Business Logic (database state, computed values)";
        $report[] = "- **B**: State Isolation (side effects prevented, counts unchanged)";
        $report[] = "- **C**: Error Semantics (HTTP status, message content)";
        $report[] = "- **D**: Data Integrity (relationships, foreign keys)";
        $report[] = "- **E**: Idempotency (repeated requests safe)";
        $report[] = "- **F**: Boundary Cases (0, negative, nonexistent IDs, null)";

        return implode("\n", $report);
    }

    private function suggestMissingCategories($used)
    {
        $all = ['A', 'B', 'C', 'D', 'E', 'F'];
        $missing = array_diff($all, $used);

        $suggestions = [
            'A' => 'Add assertDatabaseHas() or assertSame() to verify state changed correctly',
            'B' => 'Add assertDatabaseMissing() or count checks to verify no side effects',
            'C' => 'Add assertResponseStatusCode() or assertResponseBodyContains()',
            'D' => 'Add assertDatabaseRow() to verify relationships/foreign keys intact',
            'E' => 'Test repeated request: $response2 = $this->post(...); assertResponseStatusCode($response2, 404);',
            'F' => 'Add boundary tests: nonexistent ID (99999), invalid ID (non-numeric), ID=0, null values',
        ];

        $result = [];
        foreach ($missing as $cat) {
            $result[$cat] = $suggestions[$cat] ?? 'Add missing assertion type';
        }

        return $result;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $path = isset($argv[1]) ? $argv[1] : './tests';

    // Handle single file
    if (is_file($path)) {
        $analyzer = new TestHonestyAnalyzer();
        echo $analyzer->analyzeFile($path) . "\n";
    } elseif (is_dir($path)) {
        $analyzer = new TestHonestyAnalyzer();
        $report = $analyzer->analyzeDirectory($path);
        echo $report . "\n";
    } else {
        echo "Error: Path not found: $path\n";
        exit(1);
    }
}
