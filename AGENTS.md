# Repository Guidelines for Agents

## Coding Standards & Invariants

### Fail-Fast Function & Method Design
- **Validate Preconditions at Entry**: Check contract invariants immediately (e.g. query placeholder count `?` vs. `count($params)`, argument types, non-empty identifiers).
- **Throw on Contract Violations**: Throw `InvalidArgumentException` on caller bugs so issues surface immediately during development and test runs, rather than causing silent corruption or downstream runtime warnings.
- **Distinguish Programmer Bugs from Operational Failures**:
  - *Contract violation / Programmer bug* (wrong parameter count, invalid query shape) $\rightarrow$ throw exception immediately (fail fast).
  - *Operational / Runtime failure* (transient DB timeout, prepare error, execute error) $\rightarrow$ return `null`/`false` or handle gracefully with statements closed.
- **Accurate Test Stubs**: Stubs in `tests/unit/` must mirror real driver behavior (such as `mysqli_stmt::bind_param()` argument count checks) to ensure contract violations are caught by unit tests.

### Testing & Verification
- Run tests via `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tests`.
- Zero-dependency unit tests live in `tests/unit/` (`run.sh`).
- Integration tests live in `tests/integration/` (`*_test.*`).
