# MASTER IMPLEMENTATION PLAN: ReBuild AI (Gaza Relief & Reconstruction Platform)
# TARGET AGENT: Lead Software Architect & Principal Engineer (Claude)
# ENVIRONMENT: Laravel 12 (PHP 8.2+), PostgreSQL with PostGIS, Redis/Horizon, Next.js 15, Flutter

## OBJECTIVE:
Execute the end-to-end development of ReBuild AI, a multi-tenant humanitarian relief and reconstruction system designed for severe connectivity and infrastructural constraints in the Gaza Strip. The system operates on an Offline-First architecture, coordinating field surveys, relief delivery, and reconstruction tracking with AI-assisted deduplication and priority indexing.

---

## EXECUTION RULES FOR CLAUDE:
1. Work sequentially through the phases below.
2. DO NOT output high-level summaries or pseudo-code. All code deliverables must be production-ready, strictly typed (PHP 8.2 / Laravel 12 standards), and complete.
3. Every component must be accompanied by comprehensive Markdown documentation located within the specified `docs/` subdirectories.
4. Stop after each phase and request confirmation before proceeding to the next.

---

### PHASE 1: System Foundation, Database Engine & Documentation Setup
**Goal:** Initialize the project architecture, set up PostGIS spatial database migrations, design the core multi-tenant data layer, and establish the documentation structure.

- **Backend & Database Deliverables (Laravel 12):**
  1. Complete migrations for:
     - `tenants` / `organizations` (multi-tenant boundaries)
     - `users` (RBAC: Admin, Coordinator, Field Agent, Contractor, Donor)
     - `beneficiaries` (National ID indexing, family metrics, vulnerability tags)
     - `damage_assessments` (Client offline UUIDs, PostGIS geography Point `SRID 4326`, damage tiers, urgent needs JSONB)
     - `assessment_images` (Local UUID, storage paths, sync states)
     - `sync_batches` (Audit logs, idempotency tracking, device tokens)
  2. Core Eloquent Models with explicit type hints, casts, and spatial scopes.
  3. API authentication setup via Laravel Sanctum with token abilities tailored to field workers and administrators.

- **Documentation Deliverables:**
  - `docs/architecture/system-overview.md` (Offline-First sync lifecycle and PostGIS spatial architecture)
  - `docs/architecture/database-schema.md` (Entity Relationship breakdown, spatial indexes, constraints)
  - `docs/security/authentication-rbac.md` (Token lifecycle, permissions matrix)

---

### PHASE 2: Offline-First Sync Engine & Queue Infrastructure
**Goal:** Build the high-throughput synchronization pipeline capable of ingesting batch submissions from offline Flutter clients without duplicate creation.

- **Backend Deliverables (Laravel 12):**
  1. `BatchSyncRequest`: Validation rules for complex nested JSON payloads containing client UUIDs, coordinates, and base64/file hashes.
  2. `SyncController@storeBatch` (`POST /api/v1/sync/batch`):
     - Strict idempotency check using Redis cache locks and database state.
     - Spatial point formatting (`ST_SetSRID(ST_MakePoint(lng, lat), 4326)`).
     - Atomic batch transactions returning immediate response with synced UUID arrays.
  3. `ProcessSyncBatchJob`: Queued Redis job for asynchronous file indexing and background processing via Laravel Horizon.

- **Documentation Deliverables:**
  - `docs/api/api-reference.md` (Complete REST API specs, headers, status codes, payload structures)
  - `docs/api/postman-collection.json` (Importable Postman collection for all endpoints)
  - `docs/testing/chaos-and-offline.md` (Edge-case scenarios: network drops, batch retries, clock drift)

---

### PHASE 3: AI Deduplication & Needs Prioritization Logic
**Goal:** Implement the logic to detect duplicate relief requests across organizations and calculate emergency intervention scores.

- **Deliverables:**
  1. Spatial & Identity Deduplication Service:
     - PostGIS proximity query checking for reports within a 15-meter radius (`ST_DWithin`).
     - National ID cross-referencing and name fuzzy matching.
  2. Priority Index Calculator:
     - Mathematical scoring based on structural damage tier, vulnerable family members, and aid history.
  3. Automated triage classification (Critical, High, Medium, Low) and duplicate flag dispatching.

- **Documentation Deliverables:**
  - `docs/analysis/deduplication-logic.md` (Mathematical formulas and spatial detection breakdown)
  - `docs/analysis/prioritization-index.md` (Detailed scoring algorithms and humanitarian weight distributions)

---

### PHASE 4: Administrative Operations & Reconstruction Tracker
**Goal:** Create endpoints and logic for project coordinators to triage assessments, manage relief batches, and assign reconstruction tasks.

- **Deliverables:**
  1. Relief Distribution Module:
     - Package allocation, verification, and dispatch logs preventing multi-organization double dipping.
  2. Reconstruction Pipeline Service:
     - Multi-stage project tracking (Debris removal, structural assessment, rehabilitation, completion).
     - Milestone sign-offs and contractor assignments.
  3. GIS Dashboard Aggregation API:
     - GeoJSON endpoints providing clustered assessment coordinates and severity heatmaps for the frontend.

- **Documentation Deliverables:**
  - `docs/security/data-protection.md` (PII safeguards, audit trails, and data isolation)
  - `docs/testing/unit-and-feature.md` (Pest / PHPUnit test suite outlines and mock factories)

---

### INITIAL INSTRUCTION:
Begin immediately with **PHASE 1**. Provide the complete Laravel 12 database migrations (incorporating PostGIS and offline UUIDs), the Eloquent models, and the Markdown files for `docs/architecture/` and `docs/security/authentication-rbac.md`.