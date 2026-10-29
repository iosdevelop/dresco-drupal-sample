# Dresco Drupal

![CI](https://github.com/iosdevelop/dresco-drupal-sample/actions/workflows/ci.yml/badge.svg)

Drupal 11 editorial, generation, validation, and publication platform for Dresco. Drupal is the recipe source of truth; `fresco_pages` publishes approved recipes as a static site through the GitHub Git Data API.

> Demo copy of a personal project, shared as a code sample. Where to look: `fresco_migrate` (Migrate API source plugin, a custom Drush command, parity/rehearsal/rollback reports with kernel tests), `fresco_recipe` (forms, validation rules, functional tests), `fresco_ai` (swappable LLM providers with a deterministic fallback, guardrails, queue-based generation), `fresco_theme`, and `.github/workflows/ci.yml` (phpcs, PHPStan, PHPUnit).

## Local development

```bash
scripts/setup-local.sh
```

Open `https://dresco-drupal.ddev.site`. Change the local administrator password before sharing the environment.

Signed-out visitors who open a protected page, such as a bookmarked `/generator`, are sent to the login form and returned to that page after signing in. Signed-in users without the needed permission still see Access denied.

The script is safe to run again: it preserves an installed database while updating dependencies, modules, database updates, the default theme, and caches. Optional first-install values can be supplied through `FRESCO_ADMIN_USER`, `FRESCO_ADMIN_PASSWORD`, `FRESCO_ADMIN_EMAIL`, and `FRESCO_SITE_NAME`.

### Branding

The app is called Dresco. The brand mark lives in `web/themes/custom/fresco_theme/`: `logo.svg` (shown in the navigation), `favicon.ico`, and `apple-touch-icon.png` (180×180, full-bleed, used for iPhone home-screen bookmarks). Regenerate the PNG and ICO from `logo.svg` with Imagick inside DDEV when the mark changes. Internal machine names (`fresco_*` modules, routes, permissions) are unchanged until the planned machine-name rename.


### AI providers

The deterministic provider works without external services. Configure provider selection at `/admin/config/fresco/ai`.

For OpenAI, supply the credential to DDEV through your shell; it is never stored in Drupal configuration:

```bash
export OPENAI_API_KEY='your-key'
ddev restart
```

DDEV runs Ollama as a local service at `http://ollama:11434` (`.ddev/docker-compose.ollama.yaml`), and `scripts/setup-local.sh` pulls `qwen2.5:3b` for recipes and `moondream` for photos, then selects Ollama as the provider. Override the models with `FRESCO_OLLAMA_MODEL` and `FRESCO_OLLAMA_VISION_MODEL`. Models are kept in the `dresco-ollama-models` Docker volume, which is named explicitly so they survive restarts and DDEV project renames. Without a GPU, one generation takes 1–3 minutes, so the generator runs it as a batch behind a progress page; if generation fails, the generator reopens with the entered values and the feedback. The model and base URL can be changed on the same settings page. Unavailable or invalid remote providers automatically fall back to the validated deterministic provider.

The generator links to a reviewed photo workflow at `/generator/photo`. Images are limited to JPEG, PNG, or WebP files up to 8 MB, sent only to the configured vision provider, and deleted from Drupal's temporary storage after the analysis attempt. Detected ingredients never enter a recipe automatically: the editor must explicitly select them first.

The generator also includes pantry staple checkboxes for common baseline ingredients like potatoes, tofu, rice, beans, flour, and peppers. Select the staples you want and click **Add selected staples** to move them into the ingredient list before generating.

### Recipe rules

The generation prompt (`fresco_ai/src/Service/PromptBuilder.php`) and the validators (`fresco_recipe/src/Validation/Rule/`) hold the house cooking conventions. Notable ones:

- **Cooking fat:** olive oil for sautéing, roasting, dressings, and baking unless the entered ingredients list a different oil or fat. The deterministic fallback defaults to olive oil too.
- **Instant Pot steps:** every step that runs Pressure Cook, Slow Cook, or the Steam program starts by putting the named food into the pot, for example "Put the potatoes, cauliflower, and 1 cup water into the Instant Pot, lock the lid, and Pressure Cook on High for 5 minutes." Steps that open with the program, or refer to "the main ingredients", fail the `appliance.unloaded_pot` check and are sent back to the model for revision; publishing is blocked until they are fixed.
- **Soup and stew liquid:** coconut milk, broth, and canned tomatoes count toward the total liquid, and water makes up the rest (4 cups of liquid with 1 cup coconut milk means 3 cups water). Grains such as rice still get their own water in addition.

### Generation quality eval

`drush fresco:ai:eval` runs the realistic requests in `web/modules/custom/fresco_ai/eval/cases.yml` through the full generation pipeline (vegan substitution, sweetener limits, model repair, and fallback) and scores each result. A valid recipe earns a point, plus one for each quality check it passes: it uses every entered ingredient, its method mentions no foods missing from the ingredient list, its method matches the selected appliance, and its title matches the case's expected dish. Run it before and after changing prompts, rules, models, or the fallback:

```bash
ddev drush fresco:ai:eval --provider=local                # deterministic fallback, a few seconds
ddev drush fresco:ai:eval --case=berry_smoothie,banana_bread
ddev drush fresco:ai:eval --provider=ollama --format=json  # about an hour on CPU
ddev drush fresco:ai:eval --provider=local --fail-under=45
```

The eval does not count against the hourly AI request limit, but it does record usage-ledger entries.

### Recipe artwork

Drupal generates accessible, deterministic SVG artwork from each recipe's title and ingredients. Artwork appears in recipe previews and library cards at `/recipes/{node}/image.svg`, is included in Recipe JSON-LD, and can be downloaded with the other recipe exports. The builder is reusable by the planned GitHub Pages publication worker, so static publication will not depend on a remote image service.

### Legacy migration

When the original PHP app is beside this repository at `../fresco`, DDEV mounts its SQLite database read-only. Run or review the idempotent migration at `/admin/content/fresco/import`; imported rows retain the legacy ID, publication URL, and generation provenance.

Use the parity report after import rehearsals to compare legacy SQLite rows with imported Drupal recipe nodes:

```bash
ddev drush fresco:migrate:parity
ddev drush fresco:migrate:parity --fail-on-mismatch
ddev drush fresco:migrate:rehearsal
ddev drush fresco:migrate:rehearsal --fail-on-issues
ddev drush fresco:migrate:rollback
ddev drush fresco:migrate:rollback --fail-on-issues
ddev drush fresco:migrate:finalize
ddev drush fresco:migrate:finalize --fail-on-issues
```

The parity report is read-only and returns JSON counts plus samples for missing, extra, or mismatched records. Add `--path=/path/to/app.sqlite` to compare a non-default legacy database. The rehearsal report combines parity and legacy-image coverage so you can see whether a cutover is ready without writing anything. The rollback report checks whether migrated recipes still retain the provenance breadcrumbs needed to back out safely.
The finalization command runs the last import pass, legacy image migration, and both readiness reports in one step so operators can rehearse the entire freeze-and-cutover sequence from a single command.

Import legacy artwork into recipe media references with:

```bash
ddev drush fresco:migrate:images
ddev drush fresco:migrate:images --overwrite
```

This command is repeatable. It downloads or copies the legacy image URL into a recipe image media entity, attaches it to the matching recipe node, and skips recipes without an imported legacy image unless `--overwrite` is provided.

### Static site publication

Drupal commits the static site to a separate static-site repository through the GitHub App, and Cloudflare Workers Builds deploys every commit to `recipes.example.com` (the repository's `wrangler.jsonc` defines the assets-only Worker and disables `workers.dev`). Because the repository is private, the only public copy sits behind Cloudflare's AI crawler blocks, firewall rules, and rate limit.

**Hidden from search.** The site has no login, so a phone or tablet in the kitchen can open any recipe link directly, but it is kept out of search results: every page and header says `noindex, nofollow, noai, noimageai`, `robots.txt` disallows all crawlers, and there is no sitemap. This hides the recipes from search engines but does not make them secret; anyone with a link can read them. Use the family flag below, or Cloudflare Access on the whole host, for recipes that must stay private. The old `iosdevelop.github.io/fresco-recipes` URLs no longer resolve.

**Family recipes.** Tick **Family only** on a recipe to publish it under `/family/recipes/…` instead of `/recipes/…`. Family recipes get their own `/family/` index, never appear in the public index, `recipes.json`, or `sitemap.xml`, are disallowed in `robots.txt`, and are served `noindex` and `no-store`. Cloudflare Access protects `recipes.example.com/family` with an email one-time code (30-day sessions); add family members to the "Dresco family" policy in Zero Trust → Access. If a public recipe becomes family-only, its old public URL turns into a content-free redirect and its public artwork is deleted.

### GitHub Pages publication

Every newly published recipe revision creates an immutable, content-hashed publication record. Review records, private `noindex` recipe previews, and the complete expected static site preview at `/admin/content/fresco/publications`. Queue workers use three bounded attempts and retain visible failure details; run them locally with:

```bash
ddev drush queue:run fresco_pages_publish
```

After importing legacy recipes or enabling the module on an existing site, visit `/admin/content/fresco/publications` and run **Queue all published recipes**. That seeds publication records for the latest published revision of each recipe so the full-site preview and static aggregate pages are not limited to recipes edited after `fresco_pages` was enabled.

The same operations are available to deployment scripts and local operators:

```bash
ddev drush fresco:pages:seed
ddev drush fresco:pages:reconcile
ddev drush fresco:pages:github-preflight
ddev drush fresco:pages:export ../fresco-pages-preview
ddev drush fresco:pages:diff-local ../fresco-pages-preview
ddev drush fresco:pages:diff-local ../fresco-pages-preview --fail-on-drift
ddev drush fresco:pages:repair
ddev drush fresco:pages:recover-stale --older-than=1800
ddev drush fresco:pages:retry PUBLICATION_ID
ddev drush fresco:pages:archive RECIPE_NODE_ID
```

The export command writes the exact expected Pages bundle to a local directory for cutover rehearsal. It refuses to replace changed files unless `--overwrite` is passed, and tombstone deletions only remove files inside the chosen export directory. `diff-local` runs the same managed-file drift classifier used for GitHub reconciliation against that local directory, which makes repair rehearsals possible without live credentials. Add `--fail-on-drift` when a CI or deployment script should stop on missing, changed, unexpected, or tombstoned files. Repo-local `.fresco-pages-preview/` and `fresco-pages-preview/` directories are ignored by Git.

If a worker is interrupted after a record enters `processing`, `fresco:pages:recover-stale` requeues records older than the configured threshold. The default threshold is 30 minutes and the command refuses thresholds under 60 seconds.

`fresco:pages:github-preflight` is read-only. In preview transport it reports that live checks are skipped; in GitHub transport it validates required settings, private key presence, installation-token exchange, branch read access, and recursive tree read access without creating blobs, commits, or branch updates. The settings form runs the same preflight before allowing the transport to be saved as `github`.

The settings form also displays the saved preflight status so missing live requirements are visible before submitting the form.

The static bundle includes recipe pages, generated SVG artwork, a root `index.html`, `recipes.json`, `.nojekyll`, `fresco-manifest.json`, and redirect pages for historical recipe slugs. It also includes files for Cloudflare static hosting, which keep the recipes away from search engines and AI crawlers:

- `robots.txt` disallows every crawler (and names the known AI crawlers in `StaticSiteRenderer::AI_CRAWLERS` explicitly) and declares `Content-Signal: search=no, ai-train=no, ai-input=no`. No `sitemap.xml` is published, and the next publish deletes any older one.
- `_headers` sends `X-Robots-Tag: noindex, nofollow, noai, noimageai` and security headers; every page also carries the same robots meta tag (`StaticSiteRenderer::ROBOTS`). These are advisory; Cloudflare's bot rules do the blocking.
- `_redirects` turns historical slugs into real 301 redirects.
- `.assetsignore` keeps `fresco-manifest.json` and repository files from being served.

To move every public URL to a new domain, rewrite the site URL and re-render all recipes and archive notices, then run the queue with the `github` transport:

```bash
ddev drush fresco:pages:rebase-site-url https://recipes.example.com --dry-run
ddev drush fresco:pages:rebase-site-url https://recipes.example.com
ddev drush queue:run fresco_pages_publish
```

Older publication records keep their original URLs as history. Use `/admin/content/fresco/publications/reconciliation` to compare the expected manifest and managed file tree with GitHub in live mode or to queue a full-site repair. Preview mode computes the same bundle but cannot write externally.

Editors can explicitly archive a public recipe from the publication overview. Archive operations are separate audited publication records: the recipe is removed from the public index, feed, and Recipe JSON-LD, its generated SVG is deleted from the Git tree, and the old recipe URL becomes a `noindex` archived page instead of continuing to expose recipe content.

Each publication record has a short correlation ID shown in the admin table, written to queue logs, and embedded in preview/live transport commit messages. Use it to trace a recipe revision from Drupal through worker logs and into the Pages commit history.

Drupal node deletion is guarded by that same policy. A recipe with a successful public Pages publication cannot be hard-deleted until its latest successful publication event is an archive/tombstone. This prevents orphaned public recipe pages and keeps the static bundle reconstructable from the publication log. Exceptional hard deletes require the restricted `bypass fresco pages archive delete guard` permission and are logged as warnings.

Publication defaults to `preview`, which cannot write to GitHub. Configure `/admin/config/fresco/pages` only after creating a GitHub App with repository Contents write permission. Set the App and installation IDs in Drupal, provide its private key outside configuration, restart DDEV, and then let DDEV switch to the `github` transport automatically when all three values are present:

```bash
export FRESCO_GITHUB_APP_PRIVATE_KEY_B64="$(base64 -w 0 ~/.config/dresco/github-app.pem)"
export FRESCO_GITHUB_APP_ID="123456"
export FRESCO_GITHUB_INSTALLATION_ID="789012"
ddev restart
```

Keep the key file outside the repository with `chmod 600` (`*.pem` is ignored by Git), and put the exports in your shell profile so every `ddev restart` picks them up. When you rotate the key in the GitHub App settings, replace the file and restart DDEV.

The live transport creates Git blobs and a tree, creates one commit based on the current branch, and advances the branch without force. No credential or installation token is persisted.

## Quality checks

```bash
ddev composer check
ddev composer audit
```

The complete extension plan and current implementation status, plus a change log of merged phases, are in [docs/dresco-drupal-extension-plan.md](docs/dresco-drupal-extension-plan.md).
