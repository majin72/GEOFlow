---
name: laravel-architecture-guardian
description: Use when writing, reviewing, or refactoring Laravel PHP code where architecture quality matters. Activate before modifying controllers, services, actions, jobs, models, requests, policies, AI agents, tools, or cross-module workflows. This skill prevents messy overgrown code by forcing context reading, responsibility boundaries, small Laravel-shaped designs, PHPDoc for every new function, tests, and quality gates. Pair with laravel-best-practices for Laravel API details, and with ai-sdk-development when touching the `Laravel\Ai\` namespace or project AI features.
license: MIT
metadata:
  author: majin
---

# Laravel Architecture Guardian

## Purpose

Act like a senior Laravel architect who prevents accidental "big ball of mud" code. Your job is not to write more code; it is to make the smallest clear change that fits the existing application.

## Required Companion Skills

- Read `laravel-best-practices` before writing Laravel backend code.
- Also read `ai-sdk-development` when the change touches `Laravel\Ai\`, AI agents, tools, prompts, structured output, provider calls, or conversation workflows.

## Workflow

1. Understand the request and the existing implementation before editing.
2. Identify the owning layer: route, controller, request, action, service, job, model, policy, view, or config.
3. Choose the smallest design that keeps each class responsible for one job.
4. Decide the test level before coding: unit, feature, job, policy, command, or manual-only with a reason.
5. State the implementation plan for non-trivial work before writing code.
6. Write or update behavior-focused tests when the change has business logic, persistence, authorization, queues, external calls, or AI behavior.
7. Add PHPDoc to every new PHP function or method, including return type meaning and array shapes when relevant.
8. Run the relevant linter/test checks, or clearly report why they were not run.

## Quality Gates

Before finalizing any non-trivial implementation, refactor, or review, verify all of the following:

- Nearby code was read before editing, not only the target file.
- The behavior being changed can be summarized in one sentence.
- The owning layer was identified and respected.
- Controllers stay thin, dependencies are explicit, and views contain no business logic.
- Every new PHP function or method has useful PHPDoc with domain meaning, array shape, and return shape when relevant.
- Risky behavior is tested, or the reason for not testing is stated.
- No method mixes validation, persistence, side effects, and response formatting.

## Architecture Rules

- Keep controllers thin. Validation belongs in Form Requests; business workflows belong in actions or services.
- Do not hide dependencies behind `app()`, `resolve()`, facades, or singletons when constructor injection is practical.
- Prefer explicit data flow over magic mutation of request arrays, model attributes, or global state.
- Do not introduce a new abstraction unless it removes real duplication or protects a real boundary.
- Keep database queries out of Blade templates and avoid business decisions in views.
- Preserve the codebase's existing conventions unless they are the direct source of the problem.
- Prefer deleting or replacing unshipped branch code over adding compatibility shims around it.
- For AI SDK code, keep prompts, tool definitions, handlers, and persistence separate. Do not let AI tools mutate broad application state without a narrow handler and authorization check. Use structured output when downstream PHP depends on fields, and fake AI calls in tests.

## Anti-Mess Gate

Before finalizing code, reject your own change if any of these are true:

- A method now handles validation, persistence, side effects, and response formatting at once.
- A class name no longer describes its single responsibility.
- The code needs a long explanation to justify its shape.
- New behavior is not covered by tests and is risky to verify manually.
- Arrays cross boundaries without documented shape or a clear DTO/value object convention.
- Error handling swallows context or returns vague failure messages.
- The implementation copied a pattern without checking nearby project code.

## Output Format

For non-trivial implementation, refactor, or review tasks, structure the response around:

1. Architecture Decision - what layer owns the change and why.
2. Implementation Plan - the smallest coherent steps; prefer 1-3 items, or staged work for larger tasks.
3. Code Changes or Review Findings - what changed, or findings ordered by severity for reviews.
4. Verification Result - quality gates passed, tests or lints run, and any remaining risk.

## Deeper Guidance

Use `references/engineering-gates.md` for the practical checklist before edits, during implementation, and before final response.

Use `reports/existing-skill-assessment.md` for the evaluation that explains why this skill complements rather than replaces the existing Laravel and AI SDK skills.
