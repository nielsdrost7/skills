---
name: "test-honesty"
description: "Identifies tests that check only response properties, not logic"
---

## Test-Honesty Skill: Specification

This skill applies to both HTTP/Feature tests and pure Unit tests. Method
names below (`assertResponseStatusCode`, `databaseSelect`, `seedInvoice`,
...) are illustrative examples of a typical custom `TestCase` helper layer —
substitute your own framework's equivalents (e.g. `$response->assertStatus()`,
`$response->assertJsonFragment()`, `expectException()`). The included
analyzer (see "Execution" below) detects assertions by *shape*, not by
these exact names, so it works unmodified across projects with different
helper conventions.

### 1. **Hollow Assertion Detection**

Identifies tests that check only response properties, not logic:

```yaml
Pattern: Response-only assertions
HOLLOW:
  - $this->assertResponseStatusCode($response, 404)
  - $this->assertResponseBodyContains($response, 'error message')

HONEST:
  - $this->assertDatabaseMissing($table, $conditions)
  - $this->assertSame($expectedCount, $actualCount)
  - $this->assertTrue($model->isPaid())
```

**Detection rules:**
- Flag tests with only `assertResponse*()` assertions (< 3 methods)
- Flag tests that never touch the database
- Flag tests missing `assertDatabaseMissing()` for error cases
- Flag tests with no pre/post state comparison

### 2. **Side-Effect Verification Gap**

Detects missing state isolation assertions:

```yaml
For error/rejection tests:
MISSING: What should NOT happen?
  NOT: Test doesn't verify no rows created
  NOT: Test doesn't verify no API calls made
  NOT: Test doesn't verify no files written
  NOT: Test doesn't verify no state mutated

For success tests:
MISSING: What SHOULD happen?
  NOT: Test doesn't verify all rows created
  NOT: Test doesn't verify related entities updated
  NOT: Test doesn't verify audit trail recorded
```

**Detection rules:**
- For routes that create/modify data: require `assertDatabaseHas()`
- For routes that reject: require `assertDatabaseMissing()`
- For routes calling external APIs: require mock spy assertion
- For payment/critical operations: require transaction state verification

### 3. **Business Logic Gap Analysis**

Identifies tests missing WHY verification:

```yaml
Example: Payment rejection test
CURRENT (shallow):
  NOT: Only checks HTTP 404
  
NEEDED (honest):
  YES: Verify balance validation ran (pre-condition)
  YES: Verify no payment row created (guard worked)
  YES: Verify invoice amount unchanged (isolation)
  YES: Verify repeated request is safe (idempotency)
  YES: Verify authorization wasn't bypassed (gate)
```

**Detection rules:**
- Require `assertDatabaseRow()` checks for gate-passing tests
- Require both "yes" and "no" paths tested
- Require boundary value tests (0, -1, max+1, null, empty string)
- Require concurrent request safety checks for critical ops

### 4. **Assertion Diversity Scoring**

Ranks test comprehensiveness on multiple axes:

```yaml
Categories (each test should touch 3+ categories):

A. Business Logic Validation
   - assertDatabaseHas/Missing()
   - assertSame/Equals() for state values
   - assertTrue/False() for computed properties

B. State Isolation / Side Effects
   - assertDatabaseMissing() for rejected requests
   - assertDatabaseCount() for batch operations
   - Spy assertions for external calls

C. Error Semantics
   - assertResponseStatusCode()
   - assertResponseBodyContains()
   - assertResponseBodyNotContains() for leaks

D. Data Integrity / Relationships
   - assertDatabaseRow() for foreign key checks
   - assertSame() for relationship fields
   - Cross-table consistency checks

E. Idempotency / Concurrency
   - Repeated request assertions
   - Transaction isolation checks
   - Race condition scenarios

F. Boundary / Edge Cases
   - Zero and negative IDs
   - Null/empty values
   - Max length strings
   - Type mismatches

Score = (categories touched / categories applicable) × 100
WARN if score < 50% (test is too narrow)

Categories B, D, and E only apply when the test does something they could
plausibly cover (I/O, a collaborator, shared state). A pure Unit test with
none of that has those categories excluded from the denominator instead of
scored as missing — a POPO/enum test isn't penalized for lacking a database
side effect it structurally can't have.
```

### 5. **Test Structure Validation**

Verifies tests follow Arrange-Act-Assert with proper checkpoint placement:

```yaml
PROBLEM PATTERNS:

1. Arrange-only checkpoint:
   // Seeds 5 rows but never verifies them
   $this->seedInvoice(...);
   
2. Act-only checkpoint:
   $response = $this->post(...);
   // Immediately checks response, ignores database
   
3. Assert gaps:
   $this->assertResponseStatusCode($response, 200);
   // Missing: state verification, side effects, relationships

YES: GOOD PATTERN:

/* Arrange */
$responseBefore = count($this->databaseSelect(...))

/* Act */
$response = $this->post(...)

/* Assert: Logic */
$this->assertResponseStatusCode($response, 404)

/* Assert: State Isolation */
$this->assertDatabaseMissing('table', $conditions)
$responsesAfter = count(...)
$this->assertSame($responseBefore, $responsesAfter)

/* Assert: Entity Integrity */
$invoice = $this->databaseFetchOne(...)
$this->assertSame($clientId, $invoice['client_id'])
```

### 6. **Missing Assertion Templates**

For each test type, generate suggested assertions:

```yaml
For: Payment rejection tests
Should include:
  - [ ] assertResponseStatusCode($response, 404)
  - [ ] assertDatabaseMissing('payments', ['invoice_id' => $invoiceId])
  - [ ] assertDatabaseRow('invoices', ['id' => $invoiceId],
      ['status' => $statusBefore])
  - [ ] assertDatabaseRow('invoice_amounts', ['invoice_id' => $invoiceId],
      ['balance' => $balanceBefore])
  - [ ] repeated request produces same result
  - [ ] message doesn't leak sensitive details

For: Authorization gate tests
Should include:
  - [ ] assertResponseStatusCode($response, 403 or 404)
  - [ ] assertNoApplicationError($response)
  - [ ] assertDatabaseMissing() for operation table
  - [ ] verify unauthorized user can't see the resource
  - [ ] verify data isolation (different user's data untouched)

For: Concurrent request tests
Should include:
  - [ ] Two requests race to claim resource
  - [ ] First wins, second rejected
  - [ ] No over-crediting or race condition
  - [ ] Both responses are deterministic (404 or success, never both)
```

### 7. **Test Coupling Detection**

Identifies tests that silently depend on execution order:

```yaml
PROBLEM: Test B requires Test A to have run first
  TestA: seedInvoice()
  TestB: uses hardcoded invoice_id = 1  ← FRAGILE

HONEST: Each test stands alone
  TestB: $invoiceId = $this->seedInvoice()
  
Detection rules:
  NOT: Hardcoded IDs in tests
  NOT: Assumptions about AUTO_INCREMENT values
  NOT: Reliance on global state
  NOT: Tests that don't call seed helpers
```

### 8. **Comment Quality Check**

Verifies assertion comments explain the WHY:

```yaml
BAD (no context):
$this->assertSame(404, $response->statusCode());

GOOD (explains purpose):
$this->assertResponseStatusCode($response, 404);
// Guard must reject an unknown client before any side effect runs

BAD (narrates the obvious):
// Assert the response status code is 404
$this->assertSame(404, $response->statusCode());

GOOD (explains business logic):
// Guard must reject an invalid client reference before the INSERT,
// so a race between two requests can't create a duplicate row
$this->assertResponseStatusCode($response, 404);
$this->assertDatabaseMissing('payments', [
    'invoice_id' => $invoiceId,
    'client_id' => $invalidId,
]);
```

### 9. **Suggested Refactoring Output**

When test is hollow, suggest the improved version:

```yaml
Current test:
  3 assertions, 1 assertion category, score: 33%

Suggested improvements:
  
  1. Add state isolation assertions:
     $this->assertDatabaseMissing('payments', [...])
     → Verifies no side effects occurred
  
  2. Add boundary value tests:
     Test with nonexistent ID (99999)
     Test with invalid ID (non-numeric)
     Test with ID = 0
     → Catches edge case bugs
  
  3. Add idempotency check:
     $response2 = $this->post(...)
     $this->assertResponseStatusCode($response2, 404)
     → Ensures repeated requests are safe
  
  4. Add entity integrity check:
     $invoice = $this->databaseFetchOne(...)
     $this->assertSame($clientId, $invoice['client_id'])
     → Verifies relationships weren't corrupted

  Improved test would have 8+ assertions spanning 5+ categories
```

### 10. **Execution Scenarios to Verify**

For each assertion category, generate required test scenarios:

```yaml
Payment rejection test scenarios:

Scenario A: Invoice doesn't exist
  Input: POST /payments with invoice_id=99999
  Expected: 404, no payment created
  
Scenario B: Invoice already paid
  Input: POST /payments for invoice with balance=0
  Expected: 404, no payment created
  
Scenario C: Payment amount exceeds balance  
  Input: POST /payments with amount > balance
  Expected: 404, no payment created
  
Scenario D: Correct data, payment should succeed
  Input: POST /payments with valid data
  Expected: 201, payment created, invoice balance updated
  
Scenario E: Repeated request with same external_id
  Input: POST /payments (same external_id as Scenario D)
  Expected: 409 or 404, no duplicate payment

Detection: Are all scenarios tested? Or is test only verifying Scenario A?
```

---

## Summary: What Makes a Test "Honest"

A test is honest when it verifies:

1. YES: **The guard works** (pre-conditions are checked)
2. YES: **The side effects happen (or don't)** (state changes verified)
3. YES: **The relationships stay intact** (no orphaned data)
4. YES: **The error is appropriate** (correct status code + message)
5. YES: **The operation is safe** (idempotent, atomic, no races)
6. YES: **Edge cases are handled** (boundary values, type mismatches)
7. YES: **The whole flow is tested** (not just happy path)

A test that only checks response status is a **performance metric**, not a **behavior test**. It tells you "the endpoint ran" but not "the endpoint did the right thing."

---

## Execution: Running the Analyzer

The test-honesty skill includes an automated analyzer that scans PHP test files and generates a comprehensive report.

**Usage:**
```bash
test-honesty <path-to-tests-directory> [--full-report]
```

**Examples:**
```bash
# Analyze all tests in the default tests/ directory
test-honesty tests/

# Analyze a specific test file or directory
test-honesty tests/Feature/PaymentFlowTest.php

# Generate full report with detailed suggestions
test-honesty tests/ --full-report
```

**Output:**
The analyzer produces a markdown report showing:
- Summary: total tests, hollow count, honest count, hollow ratio
- Per-test scores and categories touched
- Specific improvement suggestions for each hollow test
- Category interpretation guide

## Known Limitations

The analyzer detects assertions by *shape* (which method was called, and
roughly what it was called on) — it does not evaluate whether the value
being asserted on is actually the right one. It can tell that a test
checked *something* about a returned value; it cannot tell whether that
something was sufficient to prove the value is correct.

Concrete example, found by manual review after the analyzer scored the
test "honest": a Peppol document-status test —

```php
public function it_gets_document_status(): void
{
    $status = $this->service->getDocumentStatus('DOC-123456');

    $this->assertIsArray($status);
    $this->assertArrayHasKey('status', $status);
}
```

`assertArrayHasKey('status', $status)` is a real assertion on a computed
return value, so it counts toward Category A (Outcome Verification) —
correctly, by the analyzer's own rules. But the test never asserts *what*
`$status['status']` actually is. A broken implementation that always
returns `['status' => null]` would pass this test just as easily as a
correct one. The gap is real; the analyzer's category system has no way
to see it, because "asserted on the right key" and "asserted the key has
the right value" look identical at the shape level it operates on.

**Practical implication**: treat a passing/honest score as evidence a test
touches real data, not as proof the test would catch a regression. For
anything the analyzer scores as honest but that guards genuinely important
behavior, still read the assertion body — the check above (does the test's
assertion pin down a *value*, not just a key's presence or a type) is cheap
to do by eye and catches what the tool structurally cannot.

**Score Interpretation:**
- **< 50%**: Test is hollow (only checks response, not logic)
- **>= 50%**: Test is honest (touches most of its applicable categories, verifies behavior)

A test's applicable-category count varies: an HTTP/Feature test touching the
database is measured against all 6 categories, while a pure Unit test with no
I/O is measured only against the categories that could apply to it (see
"Assertion Diversity Scoring" above).
