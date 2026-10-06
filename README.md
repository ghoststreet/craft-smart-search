# Smart Search for Craft CMS

**Better site search and AI Answer for Craft CMS.** Smart Search ranks results by meaning as well as by keywords, corrects typos, and can write a short AI Answer with cited sources when you want one. The index lives in Craft's own database, so there is nothing extra to host.

## Features

- **Hybrid ranking:** keyword scoring blended with meaning-based matching, with typo correction built in.
- **Works without an API key:** keyword search, typo correction and related terms learned from your own content. Add an OpenAI or OpenRouter key for stronger meaning-based ranking and AI Answer.
- **Enhanced Craft search:** your existing `craft.entries.search()` and GraphQL `search` queries rank by Smart Search, with no template changes.
- **AI Answer:** a short summary with citations, as JSON or streamed, with a daily spend cap and per-visitor limits.
- **Templates, HTTP API and GraphQL:** `craft.smartSearch.search()` and `aiAnswer()` in Twig, JSON and streaming endpoints for headless front ends, and GraphQL queries with per-schema permissions.
- **Control panel tools:** a Dashboard with usage and spend, Insights reports (top, zero-result and trending queries), an Index page with per-entry inspect, exclude and re-index, and a Preview that compares Craft's search, Smart Search and AI Answer side by side.
- **Runs in production:** settings live in project config, secrets are environment variables, and admins can use the Dashboard, Index, Insights and Preview and view settings read-only where admin changes are off.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- MySQL, MariaDB or PostgreSQL (whichever Craft already uses)
- A running Craft queue (indexing runs as queue jobs)
- Optional: an OpenAI or OpenRouter API key

## Install

Install from the Plugin Store, or with Composer:

```bash
composer require ghoststreet/craft-smart-search
./craft plugin/install smart-search
```

Installing queues the first index of your content automatically. To add an API key, open **Smart Search → Settings → Connections**, choose a provider and reference your key as an environment variable (for example `$OPENROUTER_API_KEY`), then click **Save**. See [Getting Started](https://github.com/ghoststreet/craft-smart-search/wiki/Getting-Started).

## Quick example

```twig
{% set results = craft.smartSearch.search(craft.app.request.getParam('q') ?? '') %}

{% for result in results %}
  <a href="{{ result.url }}">{{ result.title }}</a>
  <p>{{ result.excerpt }}</p>
{% endfor %}
```

## Documentation

Full documentation lives in the **[Smart Search Wiki](https://github.com/ghoststreet/craft-smart-search/wiki)**.

Set up
- [Getting Started](https://github.com/ghoststreet/craft-smart-search/wiki/Getting-Started)
- [Requirements](https://github.com/ghoststreet/craft-smart-search/wiki/Requirements)
- [Installation](https://github.com/ghoststreet/craft-smart-search/wiki/Installation)
- [Connections](https://github.com/ghoststreet/craft-smart-search/wiki/Connections) (providers, keys and models)
- [Running Without an API Key](https://github.com/ghoststreet/craft-smart-search/wiki/Running-Without-an-API-Key)

Build
- [Using Search in Templates](https://github.com/ghoststreet/craft-smart-search/wiki/Using-Search-in-Templates)
- [Enhanced Craft Search](https://github.com/ghoststreet/craft-smart-search/wiki/Enhanced-Craft-Search)
- [Using the API](https://github.com/ghoststreet/craft-smart-search/wiki/Using-the-API) (headless and external apps)
- [Using GraphQL](https://github.com/ghoststreet/craft-smart-search/wiki/Using-GraphQL)
- [AI Answer Setup](https://github.com/ghoststreet/craft-smart-search/wiki/AI-Answer-Setup)
- [Developer Events](https://github.com/ghoststreet/craft-smart-search/wiki/Developer-Events)

Tune and run
- [Indexing Your Content](https://github.com/ghoststreet/craft-smart-search/wiki/Indexing-Your-Content)
- [Tuning Search Results](https://github.com/ghoststreet/craft-smart-search/wiki/Tuning-Search-Results)
- [Costs and Limits](https://github.com/ghoststreet/craft-smart-search/wiki/Costs-and-Limits)
- [Security](https://github.com/ghoststreet/craft-smart-search/wiki/Security)
- [Configuration and Production](https://github.com/ghoststreet/craft-smart-search/wiki/Configuration-and-Production)
- [Dashboard and Insights](https://github.com/ghoststreet/craft-smart-search/wiki/Dashboard-and-Insights)
- [Console Commands](https://github.com/ghoststreet/craft-smart-search/wiki/Console-Commands)
- [Upgrading and Uninstalling](https://github.com/ghoststreet/craft-smart-search/wiki/Upgrading-and-Uninstalling)

Reference
- [Settings Reference](https://github.com/ghoststreet/craft-smart-search/wiki/Settings-Reference)
- [Troubleshooting](https://github.com/ghoststreet/craft-smart-search/wiki/Troubleshooting)

## Support

Email **dev@ghost.st** for bugs or questions.

## License

Licensed under the [Craft License](https://craftcms.github.io/license/). A license is required for each Craft project running Smart Search in production.

---

Built by [Ghost Street](https://ghost.st)
