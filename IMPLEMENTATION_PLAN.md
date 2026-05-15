## Stage 1: Article Search Tool
**Goal**: Add configurable Tavily-powered web search for article generation.
**Success Criteria**: Admin can configure Tavily search; worker attaches the search Tool only when enabled; repeated searches use cache.
**Tests**: Unit tests for config/service/tool behavior and feature test for admin settings persistence.
**Status**: Complete

## Stage 2: Article Search Worker Integration
**Goal**: Let article generation use the Tavily Tool through Laravel AI's existing tool loop.
**Success Criteria**: Existing generation still works when disabled; enabled mode passes the Tool and records useful metadata.
**Tests**: Worker prompt/tool tests and existing worker prompt tests.
**Status**: Complete

## Stage 3: AI Ops Runtime Foundation
**Goal**: Create persistent AI ops sessions/runs/steps/attachments with queue execution, realtime progress, refresh recovery, and cancellation.
**Success Criteria**: A run can be created, executed in the background, observed in real time, recovered after refresh, and cancelled cooperatively.
**Tests**: Feature tests for run state transitions, cancellation, and broadcast payload redaction.
**Status**: Complete

## Stage 4: AI Ops Global Widget
**Goal**: Add a global admin AI ops widget with text/file/image input and live step display.
**Success Criteria**: Admin can open the widget from any backend page, submit a request, see live steps, confirm a plan, and cancel execution.
**Tests**: Browser or feature coverage for widget shell and API integration.
**Status**: Complete

## Stage 5: AI Ops Management Tools
**Goal**: Gradually expose safe management Tools for tasks, articles, categories, materials, and settings.
**Success Criteria**: Tools reuse application services, support dry-run/confirmation/idempotency, and verify persisted state after writes.
**Tests**: Tool-level tests for permissions, dry-run output, execution, and verification.
**Status**: Not Started
