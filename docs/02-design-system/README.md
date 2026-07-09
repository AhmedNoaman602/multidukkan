# Design System — Stub (lives in `multidukkan-frontend`)

This backend repo is the wrong home for design tokens. The design system belongs in `multidukkan-frontend`, next to the Tailwind config that implements it. This stub defines **what must exist there** so a future session can build it deliberately:

- `design-principles.md` — density-first (POS screens are used all day), RTL-first (Arabic UI), touch-friendly targets for counter use.
- `tokens.md` — spacing scale, type scale, color roles (semantic: `danger` = debt, `success` = paid — money states get reserved colors), all as Tailwind theme extensions, not ad-hoc classes.
- `components.md` — standards for the recurring primitives: money display (always 2dp, EGP, color-coded sign), quantity+unit input (base/secondary aware), customer/product pickers.
- `accessibility.md` — keyboard-first POS entry, focus order, contrast.

Rule until then: **no new visual primitives in the frontend without checking for an existing one.** Duplicated money-display components are the frontend equivalent of the ledger bug.

---
**Related**: [03-ux-patterns](../03-ux-patterns/README.md), [05-frontend](../05-frontend/README.md). **Open questions**: monorepo vs cross-repo docs — currently cross-repo. **Last reviewed**: 2026-07-08.
