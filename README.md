# SuluAIBundle

AI-assisted SEO meta generation for [Sulu](https://sulu.io/) 3.

- A **Settings** page (Settings → AI Settings) to configure an OpenAI-compatible
  API (base URL, model, API key, enabled toggle).
- A **Generate meta with AI** button on a page's **Meta / SEO** tab that sends the
  saved page's content to the API and fills the meta title, description and
  keywords.

## Requirements

* PHP >= 8.2
* Sulu >= 3.0
* Symfony >= 6.4

## Installation

```bash
composer require marcostastny/sulu-ai-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    /* ... */
    Marcostastny\SuluAIBundle\SuluAIBundle::class => ['all' => true],
];
```

Import the admin API routes in `config/routes_admin.yaml`:

```yaml
sulu_ai_admin_api:
    resource: '@SuluAIBundle/Resources/config/routing_admin.yaml'
    prefix: /admin/api
```

Update the schema (Sulu uses the admin kernel):

```bash
bin/adminconsole doctrine:schema:update --force
```

Import the admin JS in your `assets/admin/index.js` (or `app.js`) and rebuild:

```js
import "sulu-ai-bundle";
```

```bash
cd assets/admin && npm install && npm run build
```

## Permissions

The bundle registers two security contexts under *Settings → Roles*, so you can
let content editors generate meta without giving them access to the API key:

| Section | Context | Grants |
|---|---|---|
| **AI Settings** | `sulu_ai.settings` | **View/Edit** the settings page (API URL, key, model) |
| **AI Meta Generation** | `sulu_ai.meta_generation` | **View** to show and use the *Generate meta with AI* button on pages |

Grant the relevant permissions to each role under *Settings → Roles*. The
generate-meta button only appears for users who have **View** on
*AI Meta Generation*, and the endpoint enforces the same permission.

## Usage

1. Open **Settings → AI Settings**, enter the API URL (e.g.
   `https://api.openai.com/v1`), model (e.g. `gpt-4o-mini`) and API key, toggle
   **Enabled**, and save.
2. Open a page, **save** it, then open its **Meta / SEO** tab.
3. Click **Generate meta with AI**. The title, description and keywords fields
   are filled from the page's content (in the current content language). Review
   and save.

The button is disabled until the page has been saved at least once (the backend
reads the saved page content).

## How it works

The button posts the page id + locale to `POST /admin/api/ai/generate-meta`. The
controller loads the saved page server-side (`PageRepositoryInterface` +
`ContentManagerInterface`), flattens its content to plain text, calls the
configured chat-completions endpoint, and returns `{title, description,
keywords}` which the button writes into the SEO fields. The API key never leaves
the server.
