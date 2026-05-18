# Engineering Gates

Use these gates when building or reviewing Laravel PHP code. They are intentionally concrete because vague "best practices" are easy for an AI agent to ignore.

## Before Editing

- Read the closest existing implementation, not only the target file.
- Name the behavior being changed in one sentence.
- Identify the owning layer and keep the change inside that boundary when possible.
- Check whether validation, authorization, persistence, side effects, and presentation already have local patterns.
- Decide the test level before coding: unit, feature, job, policy, command, or manual-only with a reason.

## Design Gate

A proposed design is acceptable only if it passes all of these checks:

- Each new class has one reason to change.
- Each new public method has an obvious caller and clear return contract.
- Dependencies are injected unless the project has a stronger local convention.
- Long procedural branches are split by domain meaning, not by arbitrary helper extraction.
- Database writes, queued side effects, external API calls, and AI calls have explicit failure behavior.
- User-facing strings, config values, and permissions are not hardcoded in business logic when the project already has a home for them.

## PHPDoc Gate

Every new PHP function or method must have a useful PHPDoc block.

Good PHPDoc should include:

- What the method does in domain terms.
- Important parameter shape for arrays, collections, DTO-like payloads, or callable arguments.
- Return shape or side effect when the native return type is not enough.
- Thrown exceptions only when they are part of the expected contract.

Avoid PHPDoc that repeats the method name or says nothing beyond the type hints.

## Laravel Layering Defaults

- Controller: HTTP orchestration only.
- Form Request: validation and authorization for request input.
- Action: one business operation with an `execute()` method when the project already uses that style.
- Service: cohesive domain capability used by multiple operations.
- Job: retryable or asynchronous work with explicit timeout, tries, and failure behavior when needed.
- Model: relationships, casts, scopes, accessors, and persistence-adjacent behavior.
- Policy/Gate: authorization decisions.
- View/Blade: presentation only; no queries or business workflow.

## AI SDK Specific Gate

When touching Laravel AI code:

- Keep prompt instructions, tool definitions, action handlers, and persistence separate.
- Do not let an AI tool mutate broad application state without a narrow handler and authorization check.
- Use structured output when downstream PHP code depends on fields.
- Fake AI calls in tests and prevent stray prompts where practical.
- Document any tool input/output array shape in PHPDoc.

## Final Review

Before final response, check:

- The smallest coherent change was made.
- No unrelated refactor slipped in.
- Tests or lints were run, or the reason is stated.
- Risky behavior has a verification path.
- New PHP functions have useful PHPDoc.
