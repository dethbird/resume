# resume
Resume generator

---

I am trying to build a little app for myself that lets me make a few selections and be able to generate a different resume for every job application. That means stuff like Set of skills that I could check off specific to each position. I want to  have a set of summaries to choose from at the top. Experience, Education, Projects, Skills sections need to be customizable. 

When I click generate, it should run a server process that then pushes a PDF to the browser to download when complete. I want to use PHP/Slim as the backend with React as the frontend with SQLite for storage. 

Ideally, I would be able to mark page breaks so that it doesn't clip mid-section. So maybe this would have to be a sort of drag-and-drop interface or something. I'm not sure yet.

I would want to use a templating system, maybe even Twig, to template the resume in HTML / CSS at 8/5 x 11 page size. 

Does this sound do-able and can you help me identify anything I may be missing in the setup for this app?


---

Short answer: totally do-able. Your stack (PHP/Slim + React + SQLite + Twig) is a comfy combo for “compose → render → print” workflows. The only “gotchas” are PDF fidelity and page-break control—both solvable with the right renderer and print-CSS.

Here’s a tight blueprint with the missing pieces filled in.

# Architecture at a glance

* **Frontend (React)**

  * State: `Zustand` (simple) or `Redux Toolkit` (if you want time-travel/history).
  * Forms: `react-hook-form` with zod schema validation.
  * Drag & drop: `dnd-kit` (modern, ergonomic) for section ordering.
  * UI: `shadcn/ui` + Tailwind for fast, consistent styling.
  * Print preview: an iframe that loads server-rendered HTML (same template) in “print mode.”
* **Backend (Slim + Twig + SQLite)**

  * DB: SQLite with strict schema + migrations (`robmorgan/phinx` or a tiny custom migrator).
  * Templates: Twig for resume body; allow multiple Twig templates + CSS themes.
  * PDF: use **wkhtmltopdf** or **headless Chrome (Puppeteer)** via PHP wrapper:

    * Easiest in PHP: **spatie/browsershot** (Puppeteer under the hood) → the best print-CSS support.
    * Alternative pure-PHP renderers (dompdf/mpdf) are fine, but CSS columns and advanced `break-inside` rules are more reliable via real browser engines.
  * Job “Generate” flow: API receives a “resume config”, renders Twig → HTML → PDF, then streams the file to the browser.

# Print-CSS you’ll want (controls page breaks)

In your template’s print stylesheet:

```css
@page { size: 8.5in 11in; margin: 0.5in; }

section, .resume-block {
  break-inside: avoid; /* a.k.a. page-break-inside: avoid */
  page-break-inside: avoid;
}

.hard-break {
  break-before: page;   /* force a new page where you drop this */
  page-break-before: always;
}

/* Keep headings with the first paragraph that follows */
h2 { break-after: avoid; page-break-after: avoid; }

/* Avoid orphaned list items */
ul, ol, li { break-inside: avoid; }
```

This, plus Browsershot/Chrome, gives you dependable “don’t split mid-section” behavior. You can also drop a `.hard-break` marker via the UI to manually force page breaks.

# Data model (SQLite)

Keep it simple and composable:

```
users
  id, email, password_hash (if you later multi-user)

skills
  id, label, group_name, weight

summaries
  id, title, body (markdown or HTML), weight

experience
  id, company, role, start_date, end_date, location, bullets_json, tags_csv, weight

education
  id, school, degree, start_date, end_date, details_json, weight

projects
  id, name, url, description, bullets_json, tags_csv, weight

templates
  id, name, twig_path, css_path, thumbnail_path

resumes
  id, name, created_at, updated_at, template_id, theme (light/dark), notes

resume_skills
  resume_id, skill_id, sort

resume_sections
  resume_id, section_type, section_ref_id (e.g., experience.id), sort, pinned (bool), hidden (bool)
```

**Why this shape?**

* You maintain a library of assets (skills, summaries, experiences, projects).
* A “resume” is just a curated ordering (a playlist!) of those assets plus a template choice.
* You can clone a resume row to create a new variant per job.

# Key API endpoints (Slim)

```
GET  /api/skills
POST /api/skills
GET  /api/summaries
POST /api/summaries
GET  /api/experience
POST /api/experience
GET  /api/projects
POST /api/projects
GET  /api/templates
POST /api/templates

POST /api/resumes          # create a resume config (selected items + order)
GET  /api/resumes/:id
PUT  /api/resumes/:id
POST /api/resumes/:id/clone

POST /api/generate         # body: { resumeId or inline config }
                           # returns: stream (application/pdf) or 303 to download url
```

# Slim route sketch (Twig → PDF → stream)

```php
$app->post('/api/generate', function (\Slim\Http\Request $req, \Slim\Http\Response $res) use ($container) {
    $data = $req->getParsedBody(); // resumeId or full config
    $resume = loadResumeConfig($data); // query + assemble sections/skills/etc.

    /** @var \Twig\Environment $twig */
    $twig = $container->get('twig');
    $html = $twig->render($resume['template']['twig_path'], ['resume' => $resume]);

    // Browsershot (headless Chrome) for high-fidelity print CSS:
    $tmpPdf = tempnam(sys_get_temp_dir(), 'resume_') . '.pdf';
    \Spatie\Browsershot\Browsershot::html($html)
        ->showBackground()
        ->format('Letter')           // 8.5" x 11"
        ->margins(10, 10, 10, 10)    // in mm; your CSS @page still applies
        ->pdf($tmpPdf);

    $stream = new \Slim\Http\Stream(fopen($tmpPdf, 'rb'));
    return $res->withHeader('Content-Type','application/pdf')
               ->withHeader('Content-Disposition','attachment; filename="resume.pdf"')
               ->withBody($stream);
});
```

# React feature map

* **Template/Theme picker:** grid of template thumbnails; light/dark toggle.
* **Section library panel:** lists Experience/Projects/Education/Summaries/Skills with tag filters and search.
* **Canvas:** drag cards to order sections; inline toggles for “include/omit”; “insert page break” button drops a `.hard-break` token between cards.
* **Preview pane:** iframe showing `/api/preview?resumeId=...` (Twig-rendered HTML). Update on change with debounce.
* **Generate button:** calls `/api/generate` → browser downloads PDF.
* **Versioning:** “Clone as new resume” to keep per-job history.
* **Import/Export:** dump/load a JSON config for portability/backups.

# What you might be missing (and will be glad you added)

* **Fidelity choice:** Use Browsershot (Chrome) rather than dompdf/mPDF for reliable `break-inside: avoid` and font handling.
* **Font licensing:** embed your preferred resume fonts and check licenses for PDF embedding.
* **Content tokens:** in Twig, support placeholders like `{{ job.title }}`, `{{ company.name }}` so you can do quick find/replace per app.
* **Skill “chips” with weights:** let the UI score and auto-trim to fit 1 page; show a live “length meter.”
* **Accessibility & ATS sanity:** keep semantic HTML; avoid heavy absolute positioning. Provide a plain-text export too.
* **Autosave + undo/redo:** small quality-of-life boost (Zustand “store with history” is easy).
* **Auth (even local):** simple session or token to keep your data private if you ever deploy.
* **Testing:**

  * PHP: Slim route tests + a renderer test that asserts the HTML has all selected blocks in order.
  * Snapshot HTML tests for templates; minimal PDF smoke test (file exists, size > N).
  * React: component tests for the DnD ordering and form validation.

# Templating tips (Twig + BEM-ish CSS)

* Keep one base layout with `block section_experience`, `block section_projects`…
* Each section partial wraps content in a block-level container with `break-inside: avoid;`.
* For “two-column skills” vs “one-column everything else,” create two modifiers: `.layout--onecol` and `.layout--twoColSkills` and swap class at the root based on template.

# Simple “hard page break” in Twig

```twig
{% for block in resume.blocks %}
  {% if block.type == 'BREAK' %}
    <div class="hard-break"></div>
  {% else %}
    {% include "sections/" ~ block.type ~ ".twig" with { data: block.data } %}
  {% endif %}
{% endfor %}
```

# Deployment notes

* Bundle Chrome for Browsershot (or install on server). If that’s annoying on shared hosting, use `wkhtmltopdf` and test your CSS—still very good.
* SQLite is perfect for single-user or small multi-user. Add WAL mode for better concurrency:

  ```sql
  PRAGMA journal_mode = WAL;
  PRAGMA synchronous = NORMAL;
  ```
* Backups: nightly JSON export of your libraries + resume variants.

If you want, I can spin up a minimal skeleton (Slim routes, Twig base template, a sample CSS with print rules, and a React DnD canvas) you can drop into your VPS and iterate from there.

---

Ok let's start from the beginning. Let's use my current resume and try and identify and final document format (JSON) that can be passed into a Twig template. So first, before anything let's wired up Slim/Twig on the VPS and get one view route, '/' that should just be stubbed out, and one api endpoint 'api/health' that just returns status ok. We will work on the template next.

So begin by adding a composer.json and getting Slim and Twig going with the 2 routes.