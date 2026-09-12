---
name: "test-honesty"
description: "Identifies tests that check only response properties, not logic"
---

## Test-Honesty Skill: Specification

### 1. **Hollow Assertion Detection**

Identifies tests that check only response properties, not logic:

```yaml
Pattern: Response-only assertions
❌ HOLLOW:
  - $this->assertResponseStatusCode($response, 404)
  - $this->assertResponseBodyContains($response, 'error message')
  
✓ HONEST:
  - $this->assertDatabaseMissing('table', $conditions)
  - $this->assertSame($expectedCount, $actualCount)
  - $this->assertTrue($invoice->isPaid())
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
  ❌ Test doesn't verify no rows created
  ❌ Test doesn't verify no API calls made
  ❌ Test doesn't verify no files written
  ❌ Test doesn't verify no state mutated

For success tests:
MISSING: What SHOULD happen?
  ❌ Test doesn't verify all rows created
  ❌ Test doesn't verify related entities updated
  ❌ Test doesn't verify audit trail recorded
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
  ✗ Only checks HTTP 404
  
NEEDED (honest):
  ✓ Verify balance validation ran (pre-condition)
  ✓ Verify no payment row created (guard worked)
  ✓ Verify invoice amount unchanged (isolation)
  ✓ Verify repeated request is safe (idempotency)
  ✓ Verify authorization wasn't bypassed (gate)
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

Score = (# categories touched / 6) × 100
⚠️  WARN if score < 50% (test is too narrow)
```

### 5. **Test Structure Validation**

Verifies tests follow Arrange-Act-Assert with proper checkpoint placement:

```yaml
❌ PROBLEM PATTERNS:

1. Arrange-only checkpoint:
   // Seeds 5 rows but never verifies them
   $this->seedInvoice(...);
   
2. Act-only checkpoint:
   $response = $this->post(...);
   // Immediately checks response, ignores database
   
3. Assert gaps:
   $this->assertResponseStatusCode($response, 200);
   // Missing: state verification, side effects, relationships

✓ GOOD PATTERN:

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
  ☐ assertResponseStatusCode($response, 404)
  ☐ assertDatabaseMissing('ip_payments', ['invoice_id' => $invoiceId])
  ☐ assertDatabaseRow('ip_invoices', ['invoice_id' => $invoiceId], 
      ['invoice_status_id' => $statusBefore])
  ☐ assertDatabaseRow('ip_invoice_amounts', ['invoice_id' => $invoiceId],
      ['invoice_balance' => $balanceBefore])
  ☐ repeated request produces same result
  ☐ message doesn't leak sensitive details

For: Authorization gate tests
Should include:
  ☐ assertResponseStatusCode($response, 403 or 404)
  ☐ assertNoApplicationError($response)
  ☐ assertDatabaseMissing() for operation table
  ☐ verify unauthorized user can't see the resource
  ☐ verify data isolation (different user's data untouched)

For: Concurrent request tests
Should include:
  ☐ Two requests race to claim resource
  ☐ First wins, second rejected
  ☐ No over-crediting or race condition
  ☐ Both responses are deterministic (404 or success, never both)
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
  ❌ Hardcoded IDs in tests
  ❌ Assumptions about AUTO_INCREMENT values
  ❌ Reliance on global state
  ❌ Tests that don't call seed helpers
```

### 8. **Comment Quality Check**

Verifies assertion comments explain the WHY:

```yaml
❌ BAD (no context):
$this->assertSame(404, $response->statusCode());

✓ GOOD (explains purpose):
$this->assertResponseStatusCode($response, 404);
// Verify merchant client guard rejected nonexistent client before any side effects

❌ BAD (narrates the obvious):
// Assert the response status code is 404
$this->assertSame(404, $response->statusCode());

✓ GOOD (explains business logic):
// Gate must reject invalid merchant clients before INSERT IGNORE
// to prevent duplicate_key_error when racing against concurrent requests
$this->assertResponseStatusCode($response, 404);
$this->assertDatabaseMissing('ip_merchant_responses', [
    'invoice_id' => $invoiceId,
    'merchant_client_id' => $invalidId,
]);
```

### 9. **Suggested Refactoring Output**

When test is hollow, suggest the improved version:

```yaml
Current test:
  3 assertions, 1 assertion category, score: 33%

Suggested improvements:
  
  1. Add state isolation assertions:
     $this->assertDatabaseMissing('ip_merchant_responses', [...])
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

1. ✓ **The guard works** (pre-conditions are checked)
2. ✓ **The side effects happen (or don't)** (state changes verified)
3. ✓ **The relationships stay intact** (no orphaned data)
4. ✓ **The error is appropriate** (correct status code + message)
5. ✓ **The operation is safe** (idempotent, atomic, no races)
6. ✓ **Edge cases are handled** (boundary values, type mismatches)
7. ✓ **The whole flow is tested** (not just happy path)

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
test-honesty tests/Feature/Core/LetsPeppolFlowTest.php

# Generate full report with detailed suggestions
test-honesty tests/ --full-report
```

**Output:**
The analyzer produces a markdown report showing:
- Summary: total tests, hollow count, honest count, hollow ratio
- Per-test scores and categories touched
- Specific improvement suggestions for each hollow test
- Category interpretation guide

**Score Interpretation:**
- **< 50%**: Test is hollow (only checks response, not logic)
- **≥ 50%**: Test is honest (touches 3+ categories, verifies behavior)
