# Public Websites Architecture — Skaibeam & MultiDukkan Marketing

**Status: Tier 2 (designed, not built).** This document describes public-facing marketing sites that do not exist yet. It is grounded in the *actual* current setup (one Laravel API repo + one React SPA repo) so the design fits reality, but nothing here is implemented. When it is built, downgrade the relevant sections to Tier 1 and correct anything that diverged.

Companion plans (actionable, step-by-step): [`../plans/skaibeam-website.md`](../plans/skaibeam-website.md), [`../plans/multidukkan-marketing-website.md`](../plans/multidukkan-marketing-website.md).

---

## 1. Context and current reality

Today there are exactly two deployables:

| Repo | What it is | URL (dev) |
|---|---|---|
| `multidukkan` | Laravel REST API (this repo) | `http://multidukkan.test/api` |
| `multidukkan-frontend` | React 19 + Vite SPA — the **authenticated application** | `http://localhost:5174` |

There is **no company website, no marketing site, no public/unauthenticated web presence.** The SPA is entirely behind auth (`PrivateRoute` checks a token in `localStorage`; `AuthGate` calls `/me` on every route change). CORS on the API allows only `http://localhost:5174` and `http://multidukkan.test` (`config/cors.php`).

The company (Skaibeam) now needs a public presence. The target topology:

```
skaibeam.com                  → Skaibeam company website        (marketing, public)
multidukkan.skaibeam.com      → MultiDukkan product website      (marketing, public)
                                 + entry point to the app (login/signup → app)
app.multidukkan.skaibeam.com  → the existing React SPA           (authenticated app)  [recommended split]
<future>.skaibeam.com         → future product marketing sites
```

The non-negotiable principle from the request and the project philosophy: **the marketing experience must be clearly separate from the authenticated application.** They have different audiences (prospects vs. paying operators), different performance profiles (SEO/first-paint vs. app interactivity), different deploy cadences, and different security surfaces.

---

## 2. Decision: how many codebases, and what renders them

### 2.1 The core choice — marketing sites are separate from the app

**Recommendation: build the two marketing sites as one new, separate frontend project (a "marketing" repo/workspace), NOT inside `multidukkan-frontend`.**

Rationale:
- The SPA is a client-rendered, auth-gated app. Marketing sites need SEO, fast first contentful paint, and public crawlability — the opposite optimization target. Bolting public routes onto the auth SPA fights `AuthGate` (which calls `/me` on every route) and ships the whole app bundle to anonymous visitors.
- Separation keeps the app's attack surface small (no public forms, no CMS, no blog rendering inside the app that holds session tokens).
- Marketing content changes far more often than app code; separate deploys prevent a copy tweak from risking an app regression.

### 2.2 Rendering approach — three options, with tradeoffs

| Option | What | Pros | Cons | Verdict |
|---|---|---|---|---|
| **A. Static site generator (Astro)** | Astro builds mostly-static HTML, islands of React where needed. Content in Markdown/MDX. | Best SEO & performance (ships ~zero JS by default); Markdown blog with no CMS; trivial hosting (static host/CDN); can reuse React components as islands. | New tool to learn; another repo. | **Recommended.** Purpose-built for exactly this (content + marketing + blog). |
| **B. Next.js (React SSR/SSG)** | Full React framework with SSG/ISR. | One familiar language (React); strong ecosystem; easy future dynamic needs. | Heavier than needed for brochure sites; Node hosting or Vercel lock-in tradeoffs; more moving parts. | Reasonable if the team prefers staying 100% React and expects dynamic pages soon. |
| **C. Vite + React SPA (like the app), pre-rendered** | Same stack as the app, add a pre-render step. | Zero new stack. | SPA SEO is a fight; pre-rendering React is bolt-on; worst first-paint of the three. | **Not recommended** for a "premium" brand site. |

**Chosen direction: Astro (Option A)**, with the explicit fallback of Next.js if the team wants to avoid a non-React tool. Both marketing sites (Skaibeam + MultiDukkan) live in **one Astro project** as either two "sites" or route groups, because they share a design system, components, and blog engine. This is the "reusable without overengineering" balance: one marketing codebase serves many product sites via shared components + per-product content, rather than a new project per product.

> If Astro is rejected, everything below still applies — only the file extensions and the islands mechanism change. The information architecture, SEO structure, and component inventory are framework-agnostic.

### 2.3 Where the app lives after this

Keep `multidukkan-frontend` as-is (the app). Recommended: serve it at `app.multidukkan.skaibeam.com` and point every "Login" / "Start free trial" CTA on the marketing sites at it. See [SaaS platform doc](./saas-platform.md) for the domain/tenant routing rationale. Moving the app to an `app.` subdomain (from bare `multidukkan.skaibeam.com`) lets the marketing site own the apex product domain for SEO while the app stays cleanly separated — but this is a **CORS + Sanctum stateful-domain change** on the API (see §7).

---

## 3. Skaibeam company website — architecture

### 3.1 Positioning (what the site must convey)

Skaibeam is a **technology company that builds software products and AI-powered solutions**, of which MultiDukkan is the flagship. The site must *not* read as a personal developer portfolio. That means: company voice ("we build"), product-led narrative, real proof (a shipping product with real customers), and a clear "what we do / what you can buy" path — not a résumé of skills.

### 3.2 Information architecture & navigation

Primary nav (persistent, minimal):

```
Skaibeam logo | Products | AI Solutions | Pricing | About | Blog | [Contact] (secondary CTA)  | [Open App] (primary CTA)
```

Footer nav (fuller): Products (MultiDukkan + "more coming"), AI Solutions, Pricing, About, Blog, Portfolio/Case studies, Contact, legal (Privacy, Terms), social, language toggle (AR/EN).

Page inventory and purpose:

| Page | Route | Purpose | Primary conversion goal |
|---|---|---|---|
| Home | `/` | Company thesis + flagship product + AI capability in one scroll | Click into Products or Pricing |
| Products | `/products` | Portfolio of Skaibeam products; MultiDukkan featured, "more coming" honest | Click through to `multidukkan.skaibeam.com` |
| Product detail (MultiDukkan) | `/products/multidukkan` | Short pitch that hands off to the dedicated MultiDukkan marketing site | Outbound to product site |
| AI Solutions | `/ai-solutions` | Skaibeam's AI capability as a service/differentiator (grounded in the real `AIService`: product descriptions, business insights, chat) | Contact / lead |
| Pricing | `/pricing` | Company-level pricing entry; routes to product-specific pricing | Start trial / contact sales |
| About | `/about` | The company story, mission, who is behind it, credibility | Trust → Contact |
| Blog | `/blog`, `/blog/[slug]` | SEO engine + thought leadership (retail tech, AI for SMBs, Egyptian market) | Newsletter / product interest |
| Portfolio / Case studies | `/portfolio`, `/portfolio/[slug]` | Proof: real deployments and outcomes (start with the founding MultiDukkan customer, anonymized if needed) | Trust → Contact/trial |
| Contact | `/contact` | Lead capture; sales + support split | Submit qualified lead |
| Legal | `/privacy`, `/terms` | Compliance | — |

**Honesty rule (inherited from the project philosophy):** with one product live, "Products" and "Portfolio" should say "MultiDukkan today, more coming" rather than fabricate a portfolio. A premium company site with one strong, real product beats a padded one.

### 3.3 User journeys

1. **SMB owner / prospect:** Home → Products → MultiDukkan product site → Pricing → Start trial → app signup.
2. **AI-services lead:** Home → AI Solutions → Contact (lead form) → sales follow-up.
3. **Credibility check (partner/investor/hire):** Home → About → Portfolio → Blog → Contact.
4. **Returning user:** any page → "Open App" → `app.multidukkan.skaibeam.com`.

### 3.4 Content hierarchy (Home, as the model page)

1. Hero: one-line company thesis ("Skaibeam builds software that runs real businesses") + primary CTA (See our products) + secondary (Talk to us).
2. Flagship product band: MultiDukkan — what it is, one screenshot/mockup, CTA to product site.
3. AI capability band: three concrete AI things Skaibeam ships (grounded in real endpoints), not buzzwords.
4. Proof band: the real customer / outcome; logos or a single strong quote.
5. "Who it's for" / market band.
6. Secondary CTA: Pricing or Contact.
7. Footer.

---

## 4. MultiDukkan marketing website — architecture

### 4.1 Positioning

MultiDukkan is a **multi-store retail management system for small Egyptian businesses**: it runs their inventory, sales, customer debt (the ledger — the killer feature), purchasing, and reporting. The marketing site sells outcomes ("know exactly who owes you what", "never oversell stock", "run multiple shops from one screen"), then proves them with the real feature set.

Audience: small/medium Egyptian retail & wholesale business owners and their managers. Bilingual reality — **Arabic-first** copy with English available (the app users operate in Arabic; see philosophy doc). RTL support is a first-class requirement, not an afterthought.

### 4.2 Landing page structure (the core page)

| # | Section | Content | Notes |
|---|---|---|---|
| 1 | Hero | Outcome headline + subhead + primary CTA (Start free trial) + secondary (Watch demo). Product screenshot/mockup. | Arabic-first, RTL. |
| 2 | Problem | The informal-credit pain ("اكتبها عليّ"), stockouts, multi-shop chaos. | Speaks the customer's language literally. |
| 3 | Solution overview | 3–4 pillars: Sell faster, Track every debt, Control stock, See your numbers. | Maps to feature sections below. |
| 4 | Feature sections (deep) | One band each — see §4.3. | Screenshot + copy + micro-benefit. |
| 5 | Multi-store band | Manage many stores/warehouses, per-store views, transfers (mark "coming" honestly if not shipped). | Only claim what's built. |
| 6 | AI band | Product descriptions, business insights, assistant chat — real `AIService` features. | Differentiator. |
| 7 | Social proof | Real customer outcome, testimonial. | |
| 8 | Pricing CTA | Plan summary + "See pricing" → pricing page/section. | Ties to billing (Part 3). |
| 9 | FAQ | See §4.5. | SEO + objection handling. |
| 10 | Signup/Demo CTA | Final conversion band: Start free trial / Book a demo. | |
| 11 | Footer | Links, language toggle, contact, legal. | |

### 4.3 Feature sections (each a marketing band with screenshot + benefit)

Grounded in the real, shipped domain (see `docs/06-domain/`). Each maps marketing copy → the real capability so claims stay honest:

| Feature band | Real capability (verify in code before launch) | Benefit headline angle |
|---|---|---|
| Products & pricing | Products, price tiers a–e, dual units, cost price (`products` table, `06-domain/products-and-units.md`) | "One catalog, five price levels, zero mental math" |
| Orders / sales | Orders, snapshot pricing, discounts, manual totals, invoices (`06-domain/orders.md`) | "Sell in seconds, invoice instantly" |
| Customers & debt (ledger) | Customers, price tiers, walk-in, the **ledger** (`06-domain/ledger.md`, `customers.md`) | "Always know who owes you what" — lead with this |
| Inventory | Stock per warehouse, low-stock, adjustments, append-only transactions (`06-domain/inventory-and-warehouses.md`) | "Never oversell, never lose track" |
| Warehouses / multi-store | Warehouses, per-store scoping | "Run every branch from one screen" |
| Suppliers & purchase orders | Suppliers, POs, weighted-average costing, supplier payments (`06-domain/suppliers-and-purchase-orders.md`) | "Know your true cost and what you owe suppliers" |
| Payments | Direct + auto (FIFO) payments, credit, refunds (`06-domain/payments-and-credit.md`) | "Take payment any way, split it automatically" |
| Reports | Daily report: revenue, profit, collections (`ReportController`) | "See today's money at a glance" |
| Audit log | Combined ledger + inventory + audit feed (`AuditLogController`) | "Every change, tracked — trust but verify" |

### 4.4 Screenshot / mockup strategy

- **Do not screenshot real customer data.** Build a **seeded demo tenant** with realistic-but-fake Arabic data and capture from that, OR use **designed mockups** (Figma) of the key screens (dashboard, order create, customer ledger, inventory). Mockups are safer for a pre-launch premium look and avoid leaking anything.
- Prefer 3–5 hero screens reused across bands over dozens of raw captures.
- Provide both light and RTL Arabic versions.
- Store as optimized `webp`/`avif`; lazy-load below the fold.

### 4.5 FAQ (objection handling + SEO)

Seed questions: What is MultiDukkan? Is it for one shop or many? Do I need internet/is my data safe? Is it in Arabic? How much does it cost? Is there a free trial? Can I track customer debt? Can I use it on my phone? What happens to my data if I stop paying? How is this different from a notebook/Excel? Each answer is a crawlable, structured `FAQPage` schema block.

### 4.6 Mobile experience

Egyptian SMB owners are mobile-first. Requirements: mobile-first layouts, tap-friendly CTAs, RTL correctness on mobile, fast load on 3G/4G (aggressive image optimization, minimal JS), sticky "Start trial" CTA on scroll. The marketing site must score well on Core Web Vitals on mid-range Android.

---

## 5. Shared design system & reusable components

One design system spans both sites (Skaibeam parent brand + MultiDukkan product accent). Reuse the app's token approach: the app already uses HSL CSS variables via Tailwind (`--primary`, `--background`, etc. in `tailwind.config.js`) and `darkMode: ["class"]`. Mirror those tokens so brand stays consistent app↔marketing without coupling code.

Reusable component inventory (build once, theme per site):

- Layout: `SiteHeader` (nav + mobile drawer), `SiteFooter`, `Container`, `Section`.
- Marketing blocks: `Hero`, `FeatureBand` (image + copy, reversible for RTL/alternating), `LogoCloud`, `TestimonialCard`, `StatBand`, `CTASection`, `PricingTable`, `FAQAccordion`, `Newsletter`.
- Content: `BlogCard`, `BlogPostLayout`, `PortfolioCard`, `CaseStudyLayout`, `Prose` (MDX styling).
- Forms: `ContactForm`, `DemoRequestForm`, `LeadForm` (all POST to a lead endpoint — see §7).
- Primitives: `Button` (variants), `Badge`, `Card`, `Accordion`, `Tabs` — can reuse the app's Radix-based primitives conceptually (`components/ui/*`).

**Visual direction:** premium, calm, confident. Generous whitespace, a restrained palette anchored on the brand primary, real product imagery over illustrations, strong typographic hierarchy, subtle motion (no gimmicks). Arabic typography must be first-class (proper font, RTL metrics). The bar is "a real SaaS company", not "a template".

---

## 6. SEO structure (both sites)

- **Rendering:** static/SSG HTML per route (the whole reason for Astro/Next) so content is crawlable without JS.
- **Per page:** unique `<title>`, meta description, canonical URL, Open Graph + Twitter cards, hreflang for AR/EN.
- **Structured data (JSON-LD):** `Organization` (Skaibeam) + `SoftwareApplication`/`Product` (MultiDukkan) + `FAQPage` (FAQ) + `BreadcrumbList` + `Article` (blog) + `Offer` (pricing).
- **Sitemaps & robots:** generated `sitemap.xml` per domain, `robots.txt` allowing crawl of marketing, **disallowing** the app subdomain.
- **Content SEO:** blog targets Egyptian retail/SMB/AI keywords in Arabic and English; each feature has a crawlable section with real headings.
- **Performance is SEO:** Core Web Vitals budget (LCP < 2.5s on 4G). Optimize images, preload fonts, minimal JS.
- **i18n:** decide URL strategy — recommended `skaibeam.com/ar/...` + `skaibeam.com/en/...` (or `ar.`/subpath) with hreflang; keep it simple, one strategy across both sites.

---

## 7. Backend touchpoints (minimal, deliberately)

The marketing sites are mostly static and should stay that way. The only backend they need:

1. **Lead / contact / demo capture.** Options, cheapest-first: (a) a third-party form service (Formspree/Getform) or email service — zero backend; (b) a small public, **rate-limited**, **CORS-restricted** endpoint on the Laravel API (`POST /api/v1/leads`) writing to a `leads` table. Recommendation: start with (a) to avoid adding public attack surface to the app API; graduate to (b) only when leads need to live in the system. If (b): it must be `throttle`-limited, CAPTCHA/honeypot-protected, validated by a FormRequest, and **not** touch any tenant data.
2. **Signup/trial handoff.** Marketing CTAs link to the app's existing `/register` flow (or a new billing-aware signup — see [pricing doc](./pricing-and-billing.md)). No new backend for the link itself.
3. **Blog:** none — content is Markdown/MDX in the marketing repo (no CMS until proven necessary).

**Security note:** adding the marketing domains means updating `config/cors.php` `allowed_origins` and Sanctum `stateful` domains **only** for endpoints that need it (the lead endpoint if built). Do not broaden CORS for the whole API to the public web. The app subdomain move (`app.multidukkan.skaibeam.com`) requires updating `SANCTUM_STATEFUL_DOMAINS` and the SPA's `baseURL` (`src/api/axios.js`, currently hardcoded to `http://multidukkan.test/api`).

---

## 8. What this architecture deliberately avoids (anti-overengineering)

- **No CMS** (Strapi/Sanity/WordPress) until non-technical people must edit content weekly. Markdown/MDX in-repo is enough for launch.
- **No new product marketing site per hypothetical product** — one shared marketing codebase + per-product content/routes. Add a product site by adding content + a route group, not a repo.
- **No merging marketing into the app SPA** — the separation is the point.
- **No public API surface beyond a single guarded lead endpoint** (and only if a form service won't do).
- **No i18n framework sprawl** — one URL strategy, hreflang, done.
- **No premature analytics/experimentation stack** — one privacy-respecting analytics tool at launch (e.g. Plausible), A/B testing later if traffic justifies it.

---

**Related documents:** [`./saas-platform.md`](./saas-platform.md) (domains, tenancy, product-platform boundary), [`./pricing-and-billing.md`](./pricing-and-billing.md) (what Pricing pages sell), [`../plans/skaibeam-website.md`](../plans/skaibeam-website.md), [`../plans/multidukkan-marketing-website.md`](../plans/multidukkan-marketing-website.md), [`../00-overview/product-and-engineering-philosophy.md`](../00-overview/product-and-engineering-philosophy.md) (bilingual reality, anti-goals), [`../../CLAUDE.md`].

**Future improvements:** add analytics/conversion instrumentation section once a tool is chosen; add a real portfolio/case-study section once a second reference customer exists.

**Open questions:** (1) Astro vs Next.js — confirm with the team's appetite for a non-React tool. (2) Does the app move to `app.` subdomain now or later? (3) AR/EN URL strategy — subpath vs subdomain. (4) One marketing repo for both sites, or separate? (recommended: one).

**Last review checklist:** [ ] Current-reality section still matches the two repos, [ ] no feature claimed that isn't in `06-domain/`, [ ] framework decision still open or recorded as an ADR. Last reviewed: 2026-07-19 (design only, nothing built).
