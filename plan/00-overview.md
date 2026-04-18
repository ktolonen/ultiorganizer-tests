# Ultiorganizer Test Harness Overview

## Purpose

This repository will host an isolated automated test harness for the production Ultiorganizer codebase under `/home/kari/code/ultiorganizer`.

The goals are:

- validate `lib/` PHP functions with unit and integration tests
- run page-render smoke tests to detect PHP runtime or rendering failures
- support multiple customizations and configuration profiles
- execute everything inside containers
- produce reports that show customization, configuration profile, suite, and test-case results
- expose an MCP layer so a development agent can run validation on a feature branch before a PR exists

## Core Decisions

- The test harness lives in a separate repository for isolation from the production server codebase.
- Ultiorganizer is treated as the system under test and is consumed read-only by the harness.
- Containerized execution is mandatory for all automated tests.
- The PoC proves one full path first: one customization, one configuration profile, unit tests, DB-backed integration tests, and a small page smoke suite.
- The MCP layer is a developer interface, not the execution engine. All test actions must exist as normal scripts first.

## Scope Split

### In scope for the PoC

- Dockerized PHP and MariaDB test environment
- test-only bootstrap and config injection
- schema initialization from `/home/kari/code/ultiorganizer/sql/ultiorganizer.sql`
- a first set of unit tests for low-coupled `lib/` functions
- a first set of integration tests for representative DB-backed `lib/` functions
- smoke tests for a small allowlist of PHP pages
- JUnit plus human-readable summary reporting
- MCP tools that can trigger the same script entrypoints and return structured results

### Out of scope for the PoC

- broad matrix coverage across all customizations and configuration variants
- deep browser or visual UI testing
- high coverage targets or coverage gates
- full regression coverage for every page and `lib/` function

## Repository Direction

Planned top-level areas after implementation:

- `docker/` for container definitions and entrypoints
- `config/` for test configuration profiles and generated config templates
- `fixtures/` for DB seed packs
- `tests/Unit`, `tests/Integration`, `tests/Smoke`
- `scripts/` for orchestration and report generation
- `mcp/` for the MCP server wrapper
- `reports/` for generated artifacts
- `plan/` for planning documents

## Defaults

- Default customization for the PoC: `cust/default`
- Default configuration profile for the PoC: one minimal test-only baseline profile
- MCP implementation language: Python
- PHP test framework: PHPUnit installed via Composer in this repository
