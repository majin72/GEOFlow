# Existing Skill Assessment

## Evaluated Skills

- `laravel-best-practices`
- `ai-sdk-development`

## Finding

The existing skills are useful, but they do not fully solve the user's stated problem: AI generating messy Laravel PHP code that needs stronger architectural guidance.

## `laravel-best-practices`

This skill already covers many correct Laravel rules:

- consistency with nearby code
- database performance and N+1 prevention
- validation, security, routing, controllers, migrations, queues, Blade, and testing
- basic architecture rules such as thin controllers, actions, dependency injection, and Laravel conventions

Its limitation is that it reads like a reference checklist. It does not strongly force the agent to:

- decide ownership boundaries before editing
- reject overgrown methods and mixed responsibilities
- require a plan before non-trivial implementation
- enforce PHPDoc on every new function
- define anti-mess gates that catch vague abstractions and procedural blobs
- connect architecture decisions to verification and tests

Conclusion: keep it as the Laravel technical rule source, but do not rely on it alone for architecture discipline.

## `ai-sdk-development`

This skill is focused on Laravel AI SDK usage:

- correct namespace and package entry points
- agents, tools, structured output, conversation memory, streaming, queueing, and fakes
- provider support and common SDK pitfalls

Its limitation is intentional: it teaches API usage, not general Laravel architecture. It does not decide where AI logic belongs in the project, how to isolate tool side effects, or how to prevent AI agent classes from becoming oversized orchestration objects.

Conclusion: use it only when AI SDK code is involved, alongside a stronger architecture skill.

## Recommendation

Create a new companion skill rather than rewriting either existing skill.

The new skill should act as a pre-implementation and review guardrail:

- load `laravel-best-practices` for Laravel details
- load `ai-sdk-development` only for AI SDK work
- force context reading, ownership boundaries, small class design, PHPDoc, tests, and final quality gates
- explicitly reject "big ball of mud" patterns before code is finalized
