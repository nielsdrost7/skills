<?php
/**
 * Test-Honesty Analyzer
 *
 * Scans PHP test files (PHPUnit-style — Feature or Unit) and scores each
 * test's assertion diversity across six behavior-verification categories:
 * A. Outcome Verification
 * B. State Isolation / Side Effects
 * C. Error / Exception Semantics
 * D. Data Integrity / Relationships
 * E. Idempotency / Concurrency
 * F. Boundary / Edge Cases
 *
 * All detection is pattern-based on assertion *shape*, not on any
 * project-specific table name, variable name, or domain vocabulary — a
 * suite of pure Unit tests over enums/value objects scores fairly
 * alongside a suite of HTTP Feature tests against a database.
 *
 * Categories B, D and E only apply to a test that does something they
 * could plausibly cover (I/O, a collaborator, shared state). A pure-logic
 * Unit test that touches none of that has those categories excluded from
 * its denominator rather than counted as missing — that's what stops a
 * Unit-test-heavy suite from being misread as mostly hollow.
 *
 * Score = (applicable categories touched / applicable categories) x 100
 * Warns if score < 50%.
 */

class TestHonestyAnalyzer
{
    private $results = [];
    private $totalTests = 0;
    private $hollowTests = [];
    private $honestTests = [];

    private const CATEGORY_NAMES = [
        'A' => 'Outcome Verification',
        'B' => 'State Isolation / Side Effects',
        'C' => 'Error / Exception Semantics',
        'D' => 'Data Integrity / Relationships',
        'E' => 'Idempotency / Concurrency',
        'F' => 'Boundary / Edge Cases',
    ];

    // Categories that require some form of I/O or shared state to mean
    // anything. A pure-logic test that never touches any of that has
    // these excluded from its denominator instead of scored as failing.
    private const CONDITIONAL_CATEGORIES = ['B', 'D', 'E'];

    // Assertion-shape patterns per category. Matched against the raw
    // method body text, so they key on structure (an assertion comparing
    // to a property/array access, a foreign-key-shaped identifier, an
    // exception assertion) rather than any specific class, table, or
    // variable name.
    private $patterns = [
        'A' => [
            '/assertDatabaseHas\s*\(/',
            '/assertSame\s*\(\s*\$\w+\s*,\s*\$\w+(->|\[)/',
            '/assertEquals\s*\(\s*\$\w+\s*,\s*\$\w+(->|\[)/',
            '/assertTrue\s*\(\s*\$\w+->\w+\s*\(/',
            '/assertFalse\s*\(\s*\$\w+->\w+\s*\(/',
            '/assertInstanceOf\s*\(/',
            '/assertObjectEquals\s*\(/',
            '/assertEqualsCanonicalizing\s*\(/',
        ],
        'B' => [
            '/assertDatabaseMissing\s*\(/',
            '/assertDatabaseCount\s*\(/',
            '/shouldNotReceive\s*\(/',
            '/->never\s*\(\s*\)/',
            '/expects\s*\(\s*\$this->never\s*\(\s*\)\s*\)/',
            '/\$\w*[Bb]efore\b[\s\S]{0,200}\$\w*[Aa]fter\b/',
            '/assertNothingDispatched|assertNotDispatched|assertNotSent/',
        ],
        'C' => [
            '/assertStatus\s*\(/',
            '/assertResponseStatusCode\s*\(/',
            '/->assert(Ok|Created|NoContent|NotFound|Forbidden|Unauthorized|Unprocessable)\s*\(/',
            '/expectException\w*\s*\(/',
            '/assertThrows\s*\(/',
            '/assertNull\s*\(/',
            '/assertNotNull\s*\(/',
        ],
        'D' => [
            '/assertDatabaseHas\s*\(/',
            '/assertSame\s*\(\s*\$\w+\s*,\s*\$\w+->\w*_id\b/',
            '/assertEquals\s*\(\s*\$\w+\s*,\s*\$\w+->\w*_id\b/',
            '/->\w+_id\b[\s\S]{0,120}->\w+_id\b/',
            '/assertSame\s*\(\s*\$\w+->\w+->\w+/', // nested relation/object graph
        ],
        'E' => [
            '/\bidempotent\b/i',
            '/\bconcurrent\w*\b/i',
            '/\brace\b/i',
            '/\btwice\b/i',
            '/repeated\s+(request|call)/i',
            '/second\s+(post|request|call)/i',
            '/\btransaction\w*\b/i',
            '/\brollback\b/i',
        ],
        'F' => [
            '/\bzero\b/i',
            '/\bnegative\b/i',
            '/\bnonexistent\b/i',
            '/\bboundary\b/i',
            '/\bedge[\s_-]?case\b/i',
            '/assertEmpty\s*\(/',
            '/\binvalid\b/i',
            '/non-?numeric/i',
        ],
    ];

    // Signals that a test does I/O, touches shared state, or drives a
    // collaborator through a double — used only to decide whether
    // categories B/D/E are applicable, never to score a category itself.
    private $ioSignalPattern = '/assertDatabase\w+\s*\(|Queue::|Mail::|Event::|Http::|Storage::|\$this->(post|get|put|patch|delete)\s*\(|Mockery::|->shouldReceive\s*\(|->expects\s*\(/';

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
            if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    private function _analyzeFile($filePath)
    {
        $content = file_get_contents($filePath);
        $relPath = str_replace(getcwd() . '/', '', $filePath);

        // Matches PHPUnit `test*` methods and Pest-style `it_*` naming,
        // with or without attributes / return type hints.
        $pattern = '/(?:#\[.*?\]\s*)*public\s+function\s+(it_[a-z0-9_]+|test[A-Za-z0-9_]*)\s*\([^)]*\)(?:\s*:\s*\??\w+)?\s*\{/';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $testName = $matches[1][$i][0];
                $startPos = $matches[0][$i][1];
                $testBody = $this->extractMethodBody($content, $startPos);

                $analysis = $this->analyzeTest($testBody);
                $this->results[$relPath . '::' . $testName] = [
                    'file' => $relPath,
                    'method' => $testName,
                    'analysis' => $analysis,
                ];

                $this->totalTests++;

                if ($analysis['is_hollow']) {
                    $this->hollowTests[] = $testName;
                } else {
                    $this->honestTests[] = $testName;
                }
            }
        }
    }

    private function extractMethodBody($content, $startPos)
    {
        $braceCount = 0;
        $inString = false;
        $stringChar = '';
        $testBody = '';

        for ($pos = strpos($content, '{', $startPos); $pos < strlen($content); $pos++) {
            $char = $content[$pos];

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

        return $testBody;
    }

    private function analyzeTest($testBody)
    {
        $assertionCount = $this->countAssertions($testBody);
        $hasIO = (bool) preg_match($this->ioSignalPattern, $testBody);

        $categoriesUsed = [];
        $applicableCategories = [];
        $notApplicable = [];

        foreach ($this->patterns as $category => $patterns) {
            $touched = $this->matchesAny($testBody, $patterns);
            $isConditional = in_array($category, self::CONDITIONAL_CATEGORIES, true);

            if ($isConditional && !$hasIO && !$touched) {
                // Nothing in this test could demonstrate a side effect,
                // a relationship, or a concurrency guarantee — exclude
                // the category rather than penalize its absence.
                $notApplicable[] = $category;
                continue;
            }

            $applicableCategories[] = $category;
            if ($touched) {
                $categoriesUsed[] = $category;
            }
        }

        $denominator = count($applicableCategories);
        $score = $denominator > 0 ? (count($categoriesUsed) / $denominator) * 100 : 0;

        return [
            'assertions' => $assertionCount,
            'categories_used' => $categoriesUsed,
            'categories_applicable' => $applicableCategories,
            'categories_not_applicable' => $notApplicable,
            'score' => round($score, 1),
            'is_hollow' => $score < 50,
            'is_unit_style' => !$hasIO,
        ];
    }

    private function countAssertions($testBody)
    {
        return preg_match_all('/\$this->assert\w+\s*\(|->assert[A-Z]\w*\s*\(|self::assert\w+\s*\(/', $testBody);
    }

    private function matchesAny($testBody, $patterns)
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $testBody)) {
                return true;
            }
        }

        return false;
    }

    private function generateReport()
    {
        $report = [];
        $report[] = "# Test-Honesty Analysis Report";
        $report[] = "";
        $report[] = "## Summary";
        $report[] = sprintf("- **Total Tests Analyzed**: %d", $this->totalTests);
        $report[] = sprintf("- **Hollow Tests** (score < 50%%): %d", count($this->hollowTests));
        $report[] = sprintf("- **Honest Tests** (score >= 50%%): %d", count($this->honestTests));
        $report[] = sprintf(
            "- **Hollow Ratio**: %.1f%%",
            $this->totalTests > 0 ? (count($this->hollowTests) / $this->totalTests * 100) : 0
        );
        $report[] = "";
        $report[] = "## Detailed Results";
        $report[] = "";

        $fileGroups = [];
        foreach ($this->results as $result) {
            $fileGroups[$result['file']][] = $result;
        }

        foreach ($fileGroups as $file => $tests) {
            $report[] = sprintf("### %s", $file);
            $report[] = "";

            foreach ($tests as $result) {
                $analysis = $result['analysis'];
                $verdict = $analysis['is_hollow'] ? ' [HOLLOW]' : ' [HONEST]';
                $report[] = sprintf("#### %s%s", $result['method'], $verdict);
                $report[] = sprintf(
                    "- **Score**: %.1f%% (%d/%d applicable categories)",
                    $analysis['score'],
                    count($analysis['categories_used']),
                    count($analysis['categories_applicable'])
                );
                $report[] = sprintf(
                    "- **Categories Used**: %s",
                    $analysis['categories_used'] ? implode(', ', $analysis['categories_used']) : 'None'
                );
                if ($analysis['categories_not_applicable']) {
                    $report[] = sprintf(
                        "- **Not Applicable**: %s (no I/O or side effects in this test)",
                        implode(', ', $analysis['categories_not_applicable'])
                    );
                }
                $report[] = sprintf("- **Assertions**: %d", $analysis['assertions']);
                $report[] = "";

                if ($analysis['is_hollow']) {
                    $report[] = "**Improvement suggestions:**";
                    $report[] = "";
                    $missing = array_diff($analysis['categories_applicable'], $analysis['categories_used']);
                    foreach ($missing as $category) {
                        $report[] = sprintf(
                            "- **%s (Category %s)**: %s",
                            self::CATEGORY_NAMES[$category],
                            $category,
                            $this->suggestionFor($category)
                        );
                    }
                    $report[] = "";
                }
            }
        }

        $report[] = "## Interpretation";
        $report[] = "";
        $report[] = "**Score < 50%**: Test is **hollow** — checks response shape or return type only, doesn't verify business logic.";
        $report[] = "";
        $report[] = "**Score >= 50%**: Test is **honest** — touches most of its applicable assertion categories.";
        $report[] = "";
        $report[] = "A category marked *not applicable* means the test has no I/O, collaborator, or shared state for that category to describe — for example, a pure Unit test over an enum has no database row to leave untouched, so State Isolation is excluded rather than counted against it.";
        $report[] = "";
        $report[] = "### Categories";
        $report[] = "- **A — Outcome Verification**: asserts a computed or persisted value, not just that something ran.";
        $report[] = "- **B — State Isolation**: proves side effects were prevented or scoped (unchanged rows, uncalled collaborators).";
        $report[] = "- **C — Error / Exception Semantics**: the failure itself is asserted (status code, exception type, error payload).";
        $report[] = "- **D — Data Integrity**: relationships and foreign keys stayed consistent.";
        $report[] = "- **E — Idempotency / Concurrency**: repeating or racing the operation is safe.";
        $report[] = "- **F — Boundary / Edge Cases**: zero, negative, nonexistent, null, or otherwise atypical input.";

        return implode("\n", $report);
    }

    private function suggestionFor($category)
    {
        $suggestions = [
            'A' => 'Assert against a value the code actually computed or persisted (e.g. assertSame($expected, $result->attribute)), not just that the call succeeded.',
            'B' => 'Add an assertion that proves no unintended side effect occurred — assertDatabaseMissing(...), a "never called" mock expectation, or a before/after comparison.',
            'C' => 'Assert on the failure itself — the HTTP status/error payload, or the thrown exception\'s type and message.',
            'D' => 'Assert a relationship or foreign-key value to prove referential integrity held after the operation.',
            'E' => 'If repeating or racing the operation should be safe, add a test that calls it twice (or concurrently) and asserts the same safe outcome both times.',
            'F' => 'Add a boundary-value case: zero, negative, a nonexistent id, or a null/empty input.',
        ];

        return $suggestions[$category] ?? 'Add an assertion covering this category.';
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $path = isset($argv[1]) ? $argv[1] : './tests';

    if (is_file($path)) {
        $analyzer = new TestHonestyAnalyzer();
        echo $analyzer->analyzeFile($path) . "\n";
    } elseif (is_dir($path)) {
        $analyzer = new TestHonestyAnalyzer();
        echo $analyzer->analyzeDirectory($path) . "\n";
    } else {
        fwrite(STDERR, "Error: Path not found: $path\n");
        exit(1);
    }
}
