# Implementation Plan — Skaibeam Company Website

**Status: not started. Do not implement without approval.** This plan is written to be executable by another AI model or engineer after the current session. Read the architecture first: [`../architecture/public-websites.md`](../architecture/public-websites.md) and [`../architecture/saas-platform.md`](../architecture/saas-platform.md).

---

## Objective

Ship a premium, SEO-optimized, bilingual (Arabic-first) **company** website at `skaibeam.com` that positions Skaibeam as a real technology company building software products and AI solutions — flagship product MultiDukkan — and routes visitors toward products, pricing, and contact. It must not read as a personal developer portfolio.

## Current state

- No public/marketing web presence exists. Only the Laravel API (`multidukkan`) and the authenticated React SPA (`multidukkan-frontend`) exist.
- No design system outside the app's Tailwind HSL tokens (`multidukkan-frontend/tailwind.config.js`).
- Domain topology (`skaibeam.com`, `*.skaibeam.com`) is planned, not provisioned.

## Desired outcome

- A separate marketing codebase (recommended **Astro**; fallback Next.js) rendering static/SSG HTML.
- Pages: Home, Products, Product detail (MultiDukkan), AI Solutions, Pricing, About, Blog (+ posts), Portfolio/Case studies, Contact, Privacy, Terms.
- Shared design system reused by the MultiDukkan marketing site (same repo).
- Lead capture working; "Open App" and "Start trial" CTAs deep-link to the app/signup.
- Lighthouse/Core Web Vitals green; full AR/EN + RTL; JSON-LD + sitemaps.

## Files likely to be created

> Paths assume a new sibling repo/workspace `skaibeam-web/` (one marketing codebase for both sites). If Astro:

- `skaibeam-web/package.json`, `astro.config.mjs`, `tailwind.config.js`, `tsconfig.json`
- `src/layouts/BaseLayout.astro`, `MarketingLayout.astro`, `BlogPostLayout.astro`
- `src/components/` — `SiteHeader`, `SiteFooter`, `Hero`, `FeatureBand`, `LogoCloud`, `TestimonialCard`, `StatBand`, `CTASection`, `PricingTable`, `FAQAccordion`, `Newsletter`, `BlogCard`, `PortfolioCard`, `LangToggle`, `Button`, `Container`, `Section`
- `src/pages/` — `index.astro`, `products/index.astro`, `products/multidukkan.astro`, `ai-solutions.astro`, `pricing.astro`, `about.astro`, `contact.astro`, `blog/index.astro`, `blog/[slug].astro`, `portfolio/index.astro`, `portfolio/[slug].astro`, `privacy.astro`, `terms.astro`
- `src/content/` — `blog/*.md(x)`, `portfolio/*.md(x)`, `config.ts` (content collections)
- `src/i18n/` — `ar.json`, `en.json`, routing helpers
- `src/styles/tokens.css` (mirrors app HSL tokens), `global.css`
- `public/` — brand assets, `robots.txt`, OG images, favicons
- `src/lib/seo.ts` (meta + JSON-LD helpers), `src/lib/leads.ts` (form submit)
- CI/deploy config (e.g. `netlify.toml`/`vercel.json`/static host + CDN)

## Files likely to be modified

- **In `multidukkan` API (only if a first-party lead endpoint is chosen over a form service):** `routes/api.php` (add public, throttled `POST /api/v1/leads`), new `app/Http/Controllers/Api/V1/LeadController.php`, `app/Http/Requests/StoreLeadRequest.php`, `config/cors.php` (add `skaibeam.com`, `multidukkan.skaibeam.com` origins for that path).
- **In `multidukkan-frontend`:** none required for the company site itself (the app only changes if it moves to `app.` subdomain — tracked in the marketing-website plan / saas-platform).
- **Docs:** downgrade `../architecture/public-websites.md` sections to Tier 1 as pieces ship; record framework choice as an ADR.

## Database changes

- **None** for a static company site.
- **Only if** first-party lead capture is built: a `leads` table (`id`, `name`, `email`, `phone`, `company`, `message`, `source`, `product_interest`, `locale`, `created_at`). No tenant data, no PII beyond the lead. Prefer a form service first (zero DB).

## Routes

- Marketing routes are file-based (Astro/Next pages) — see files above.
- API: at most one new public route `POST /api/v1/leads` (throttled, CORS-restricted, honeypot/CAPTCHA).

## Components

See "Files created". Key reusable ones: `Hero`, `FeatureBand`, `CTASection`, `PricingTable`, `FAQAccordion`, `SiteHeader/Footer`, `LangToggle`. All theme via CSS tokens so the MultiDukkan site reuses them.

## Backend changes

- Default: **none** (use a third-party form/email service).
- Optional: `LeadController` + `StoreLeadRequest` + `leads` table + throttle + CORS entry. Must not import or touch any domain/`LedgerService` code.

## Frontend changes

- Entirely new marketing codebase (Astro/Next). No changes to the app SPA for this plan.

## Dependencies

- New: `astro` (+ `@astrojs/tailwind`, `@astrojs/sitemap`, `@astrojs/mdx`, `@astrojs/react` for islands) **or** `next` + `react`. `tailwindcss`. An analytics script (e.g. Plausible). Optional form service SDK. RTL-capable Arabic web font.
- No new PHP dependencies unless the lead endpoint is built (none needed — core Laravel suffices).

## Implementation phases

1. **Scaffold + design system** — new repo, Tailwind + tokens mirrored from app, base layouts, header/footer, Button/Container/Section, AR/EN i18n + RTL plumbing, deploy pipeline to a staging URL.
2. **Core pages** — Home, About, Contact (with working lead capture via form service), Products (+ MultiDukkan detail stub linking to product site). Get SEO scaffolding (meta, JSON-LD `Organization`, sitemap, robots) in place.
3. **AI Solutions + Pricing** — AI Solutions page grounded in real `AIService` capabilities; Pricing page (reads plan data statically for now; wires to billing later — see pricing plan).
4. **Blog + Portfolio** — content collections, post/case-study layouts, `Article` JSON-LD, index + detail pages, RSS optional. Seed 2–3 honest posts and the one real MultiDukkan case study.
5. **Polish + launch** — performance pass (CWV budget), full RTL QA, accessibility pass, OG images, legal pages, analytics, 404, redirects, DNS + TLS for `skaibeam.com`.

## Acceptance criteria

- All listed pages exist, render as static HTML (view-source shows content without JS), and are reachable in AR and EN with correct RTL.
- Navigation + footer consistent; "Open App" → app URL; "Start trial"/"Contact" convert.
- Lead form submits and is received (service inbox or `leads` row); spam-protected.
- Lighthouse ≥ 90 across Performance/SEO/Best-Practices/Accessibility on mobile; LCP < 2.5s on throttled 4G.
- Each page has unique title/meta/canonical/OG + appropriate JSON-LD; `sitemap.xml` + `robots.txt` present; app subdomain disallowed in robots.
- Content contains **no** fabricated products/portfolio — "more coming" where honest.
- Brand tokens match the app's primary palette.

## Testing requirements

- Build must pass with zero errors; links checked (no dead internal links).
- Lighthouse CI (or manual) on Home, Products, Pricing, a blog post — mobile profile.
- RTL visual QA on Home, a FeatureBand page, Contact, a blog post (Arabic).
- Form submission E2E (happy path + spam/honeypot rejection).
- Accessibility: keyboard nav, focus states, alt text, color contrast, landmark structure.
- If lead endpoint built: FormRequest validation test, throttle test, CORS test, and a test asserting it touches no tenant/domain tables.
- Cross-browser smoke (Chrome, Safari, mobile Safari, Android Chrome).

## Security considerations

- Static site = minimal surface; keep it that way. No secrets in the client bundle (analytics keys are public-safe only).
- If the lead endpoint is built: public + throttled + honeypot/CAPTCHA + FormRequest validation; **restrict CORS to the marketing origins**, not `*`; never expose it to tenant data; log/monitor for abuse.
- Strict CSP on the marketing site; no inline untrusted HTML; sanitize any MDX-embedded HTML.
- Do not share the app's session/token surface; marketing domains stay off the app's CORS/Sanctum stateful list (except the one lead path if used).
- Privacy: analytics must be privacy-respecting (cookie-light); publish a real Privacy policy; comply with lead-consent basics.

## What should explicitly NOT be implemented yet

- **No CMS** (content stays in-repo Markdown/MDX).
- **No first-party lead endpoint** unless the form-service route is rejected.
- **No app changes / app-subdomain move** in this plan (separate concern).
- **No live pricing/billing wiring** — Pricing page is static until the billing plan ships.
- **No A/B testing, personalization, or marketing-automation stack.**
- **No per-tenant or dynamic pages** — the company site is fully static.
- **No fabricated portfolio/testimonials** — ship with the one real case study, expand later.

---

**Related documents:** [`../architecture/public-websites.md`](../architecture/public-websites.md), [`../architecture/saas-platform.md`](../architecture/saas-platform.md), [`./multidukkan-marketing-website.md`](./multidukkan-marketing-website.md), [`./pricing-and-billing.md`](./pricing-and-billing.md).

**Future improvements:** analytics/conversion instrumentation; expand portfolio; consider CMS only if non-technical editing becomes frequent.

**Open questions:** Astro vs Next.js; one shared marketing repo vs two; AR/EN URL strategy; form service vs first-party endpoint. (All inherited from the architecture doc.)

**Last review checklist:** [ ] still no marketing site in prod, [ ] framework decision recorded before scaffolding, [ ] claims match real product. Last reviewed: 2026-07-19.
