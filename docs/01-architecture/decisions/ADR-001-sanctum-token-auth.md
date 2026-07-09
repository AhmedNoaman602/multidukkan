# ADR-001: Laravel Sanctum token auth

**Status**: Accepted **Date**: recorded 2026-07-08 (decision predates record)

## Context
The API is consumed by a separate React SPA (different origin during development, Herd + Vite) and later mobile apps. We needed auth that works for both without OAuth-server complexity.

## Decision
Laravel Sanctum with **personal access tokens** (not SPA cookie mode). Routes guard with `auth:sanctum`. Endpoints: `POST /login`, `POST /register`, `POST /logout`, `GET /me` (`AuthController`).

## Alternatives rejected
- **Passport (OAuth2)**: full OAuth server for a first-party SPA is pure overhead; no third-party clients exist or are planned.
- **Sanctum SPA cookie mode**: ties the frontend to same-site domain configuration; token mode works identically for the SPA and future mobile apps.
- **JWT packages (tymon/jwt-auth)**: another dependency to maintain; Sanctum is first-party and sufficient.

## Consequences
- Tokens must be stored client-side; frontend owns secure storage and attaches `Authorization: Bearer`.
- Token revocation = deleting rows in `personal_access_tokens`; no token expiry policy is configured yet (open item).
- Mobile apps (roadmap) need zero backend auth changes.

---
**Related**: [Backend Architecture](../backend-architecture.md). **Open questions**: token expiry / rotation policy before multi-tenant launch. **Last reviewed**: 2026-07-08.
