# Implementation Plan — MultiDukkan Marketing Website

**Status: not started. Do not implement without approval.** Executable by another AI/engineer. Read [`../architecture/public-websites.md`](../architecture/public-websites.md) (§4) and [`../architecture/saas-platform.md`](../architecture/saas-platform.md) first. Every feature claim must be verified against `docs/06-domain/` before it goes on the page.

---

## Objective

Ship a product-focused, Arabic-first marketing site at `multidukkan.skaibeam.com` that explains what MultiDukkan is, who it's for, the problems it solves, and its real feature set (products, orders, customers, inventory, warehouses, suppliers, purchase orders, payments, ledger, reports, audit logs, multi-store), then converts visitors to a free trial or demo. It must be clearly separate from the authenticated app.

## Current state

- No marketing site. The app SPA (`multidukkan-frontend`) is auth-gated and not built for SEO.
- Real, shipped features are documented in `docs/06-domain/` and `docs/07-business-rules/` — the source of truth for honest claims.
- The app is served at `http://localhost:5174` (dev) with API base hardcoded to `http://multidukkan.test/api` (`src/api/axios.js`).

## Desired outcome

- A high-converting landing page + supporting pages built in the **same marketing codebase** as the Skaibeam site (shared components, per-product content).
- Feature sections with screenshots/mockups (from a seeded demo tenant or Figma — never real customer data).
- Pricing CTA, Signup/Demo CTA, FAQ, SEO structure, excellent mobile + RTL.
- CTAs deep-link to the app signup / a demo request.

## Files likely to be created

> In the shared `skaibeam-web/` repo, as a product route group / second "site":

- `src/pages/multidukkan/` (or a dedicated build target) — `index.astro` (landing), `features/*.astro` (optional deep pages per feature), `pricing.astro`, `faq.astro`, `demo.astro`
- `src/content/multidukkan/` — feature copy (AR/EN), FAQ entries, testimonials
- Product-specific components: `ProductHero`, `LedgerBand`, `InventoryBand`, `MultiStoreBand`, `AIBand`, `ScreenshotFrame`, `DemoRequestForm`, `TrialCTA`
- `src/assets/multidukkan/` — optimized screenshots/mockups (webp/avif), light + RTL variants
- SEO: `SoftwareApplication`/`Product` + `FAQPage` + `Offer` JSON-LD helpers
- (If seeded demo route for screenshots) a documented seeder in `multidukkan` (see DB changes)

## Files likely to be modified

- **Shared marketing components** created in the Skaibeam plan (reused/extended here).
- **`multidukkan` API — only if** a first-party demo/lead endpoint is chosen: reuse the same `POST /api/v1/leads` (add `product_interest=multidukkan`) from the Skaibeam plan; no new endpoint.
- **`multidukkan-frontend` — only if** the app moves to `app.multidukkan.skaibeam.com`: `src/api/axios.js` (`baseURL`), and API-side `config/cors.php` + `SANCTUM_STATEFUL_DOMAINS`. **This subdomain move is optional and can be deferred**; document it but don't require it for launch.
- **`database/seeders/`** (optional) — a `DemoTenantSeeder` for screenshot capture (see below).

## Database changes

- **None** for the marketing site itself.
- **Optional (screenshots):** a `DemoTenantSeeder` that creates a throwaway tenant with realistic **fake** Arabic data to capture screenshots. This writes only to a non-production/demo database. No schema change. Alternative: Figma mockups (no DB at all) — preferred pre-launch.
- Lead capture reuses the `leads` table from the Skaibeam plan (if that route is chosen).

## Routes

- File-based marketing routes under the MultiDukkan product group.
- No new app routes required. Reuses `/register` (or billing-aware signup) for trial start, and the shared `/api/v1/leads` for demo requests (optional).

## Components

Landing bands mapped to real features (verify each in `06-domain/` before publishing):

| Component | Backs onto | Source doc to verify |
|---|---|---|
| `ProductHero` | product positioning | `00-overview/product-and-engineering-philosophy.md` |
| Products/pricing band | price tiers a–e, dual units, cost price | `06-domain/products-and-units.md` |
| Orders band | orders, snapshots, discounts, invoices | `06-domain/orders.md`, `07-business-rules/order-lifecycle.md` |
| `LedgerBand` (hero feature) | customer debt ledger | `06-domain/ledger.md`, `customers.md` |
| `InventoryBand` | stock, low-stock, adjustments, transactions | `06-domain/inventory-and-warehouses.md` |
| `MultiStoreBand` | warehouses, per-store scoping (transfers = "coming" if unbuilt) | `06-domain/inventory-and-warehouses.md` |
| Suppliers/PO band | suppliers, POs, weighted-avg cost, supplier payments | `06-domain/suppliers-and-purchase-orders.md` |
| Payments band | direct + auto (FIFO), credit, refunds | `06-domain/payments-and-credit.md` |
| Reports band | daily report (revenue/profit/collections) | `ReportController` |
| Audit-log band | combined ledger+inventory+audit feed | `AuditLogController` |
| `AIBand` | describe-product, insights, chat | `AIService` |
| `FAQAccordion` | objection handling | (this plan §FAQ) |
| `TrialCTA` / `DemoRequestForm` | conversion | — |

## Backend changes

- Default: **none**.
- Optional: reuse `LeadController`/`leads` from the Skaibeam plan for demo requests. Optional `DemoTenantSeeder` for screenshots (demo DB only).

## Frontend changes

- New product marketing pages in the shared marketing repo. **No changes to the app SPA** unless the optional `app.` subdomain move is done.

## Dependencies

- Same as the Skaibeam site (Astro/Next + Tailwind + MDX + sitemap + analytics). No new backend deps unless the optional lead endpoint/seeder is built (core Laravel only).

## Implementation phases

1. **Landing MVP** — hero, problem, solution pillars, 3–4 top feature bands (lead with the **ledger**), trial CTA, footer. Arabic-first + RTL. Reuse shared components. Static pricing summary linking to pricing page.
2. **Full feature coverage** — remaining bands (suppliers/PO, payments, reports, audit log, multi-store, AI), each with a screenshot/mockup and honest copy verified against `06-domain/`.
3. **Conversion + FAQ + demo** — FAQ (with `FAQPage` schema), demo request form, final signup CTA, pricing page (static until billing ships), social proof band with the real case study.
4. **SEO + mobile + launch** — JSON-LD (`SoftwareApplication`, `Offer`, `FAQPage`), sitemap, meta/OG, CWV budget on mid-range Android, sticky mobile CTA, RTL QA, DNS/TLS for `multidukkan.skaibeam.com`.

## Acceptance criteria

- Landing page communicates what/who/problems within the first two screens, Arabic-first, RTL-correct.
- Every listed feature (products, orders, customers, inventory, warehouses, suppliers, POs, payments, ledger, reports, audit logs, multi-store) has a section; **no claim exceeds what `06-domain/` documents as built** (unbuilt = "coming soon" or omitted).
- Screenshots/mockups contain **no real customer data**.
- Trial CTA → app signup; Demo CTA → working demo/lead capture.
- Mobile Lighthouse ≥ 90; LCP < 2.5s throttled 4G; sticky trial CTA on mobile.
- FAQ + JSON-LD present; unique meta/OG/canonical; sitemap; robots disallows the app subdomain.
- Visually + structurally distinct from the authenticated app (a visitor can't confuse marketing with the product).

## Testing requirements

- Build passes; no dead links; images optimized and lazy-loaded.
- Lighthouse (mobile) on landing + pricing + a feature deep page.
- RTL + Arabic QA on landing and each feature band.
- Demo/lead form E2E (happy + spam rejection) if built.
- Accessibility pass (keyboard, contrast, alt text, landmarks).
- **Content-accuracy check:** a reviewer cross-references each feature claim against `06-domain/` (a required gate, not optional).
- If `DemoTenantSeeder` built: assert it runs only against demo/non-prod DB and produces no real PII.

## Security considerations

- Same static-site posture as the Skaibeam plan; minimal surface.
- **Screenshots must never leak real tenant data** — enforce via seeded demo data or mockups; review every image before publish.
- If the app subdomain move is done: update CORS + Sanctum stateful domains carefully; change the SPA `baseURL`; verify tokens/sessions still isolated and marketing origins are not on the app's trusted list beyond what's needed.
- Lead/demo endpoint (if built): throttled, honeypot/CAPTCHA, FormRequest-validated, CORS-restricted, no tenant-data access.
- Marketing analytics privacy-respecting; real Privacy policy linked.

## What should explicitly NOT be implemented yet

- **No live pricing/billing integration** — pricing is static until the billing plan ships.
- **No in-app changes** beyond the *optional, deferrable* subdomain move (prefer to defer).
- **No embedding of the real app** or authenticated previews in the marketing site.
- **No fabricated features** — anything not in `06-domain/` is "coming soon" or absent (esp. stock transfers, notifications, mobile apps — all roadmap/unbuilt).
- **No CMS, no A/B testing, no personalization.**
- **No real customer data in any asset.**

---

**Related documents:** [`../architecture/public-websites.md`](../architecture/public-websites.md) (§4), [`../architecture/saas-platform.md`](../architecture/saas-platform.md), [`./skaibeam-website.md`](./skaibeam-website.md) (shared components), [`./pricing-and-billing.md`](./pricing-and-billing.md), and all of `../06-domain/` (the honesty source of truth).

**Future improvements:** interactive product tour; live demo tenant (read-only) once safe; localized case studies as more customers land.

**Open questions:** defer or do the `app.` subdomain move now? Seeded-demo screenshots vs Figma mockups for launch? (recommend mockups first.)

**Last review checklist:** [ ] every feature claim traced to `06-domain/`, [ ] no real data in assets, [ ] app and marketing clearly separate. Last reviewed: 2026-07-19.
