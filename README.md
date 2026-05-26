# Smart Search for Craft CMS

**AI-powered Smart Search and AI Answer for Craft CMS.** Smart Search blends semantic (meaning-based) and keyword ranking, and can write a short AI Answer with sources when you want one.

## Requirements

- Craft CMS 4.0+ or 5.0+
- PHP 8.2+
- PostgreSQL 12+ with the [`pgvector`](https://github.com/pgvector/pgvector) extension (MySQL is not supported)
- OpenAI API key

## Install

```bash
composer require ghoststreet/craft-smart-search
./craft plugin/install smart-search
```

Then, in Craft → **Smart Search → Settings → Connections**, add your OpenAI key and Postgres details.

> One-time database setup is required before the plugin can index anything. See [Installation](https://github.com/ghoststreet/craft-smart-search/wiki/Installation) in the wiki.

## Quick example

```twig
{% set results = craft.smartSearch.search('how do I reset my password') %}

{% for r in results %}
  <a href="{{ r.url }}">{{ r.title }}</a>
  <p>{{ r.excerpt }}</p>
{% endfor %}
```

## Documentation

Full documentation lives in the **[Smart Search Wiki](https://github.com/ghoststreet/craft-smart-search/wiki)**:

- [Getting Started](https://github.com/ghoststreet/craft-smart-search/wiki/Getting-Started)
- [Installation](https://github.com/ghoststreet/craft-smart-search/wiki/Installation) (including the one-time database setup)
- [Using Search in Templates](https://github.com/ghoststreet/craft-smart-search/wiki/Using-Search-in-Templates)
- [Using the API](https://github.com/ghoststreet/craft-smart-search/wiki/Using-the-API) (headless / external apps)
- [Tuning Search Results](https://github.com/ghoststreet/craft-smart-search/wiki/Tuning-Search-Results)
- [AI Answer Setup](https://github.com/ghoststreet/craft-smart-search/wiki/AI-Answer-Setup)
- [Costs and Limits](https://github.com/ghoststreet/craft-smart-search/wiki/Costs-and-Limits)
- [Security](https://github.com/ghoststreet/craft-smart-search/wiki/Security)
- [Troubleshooting](https://github.com/ghoststreet/craft-smart-search/wiki/Troubleshooting)
- [Settings Reference](https://github.com/ghoststreet/craft-smart-search/wiki/Settings-Reference)

## Support

Email **dev@ghost.st** for bugs or questions.

## License

Licensed under the [Craft License](https://craftcms.github.io/license/). A license is required for each Craft project running Smart Search in production.

---

Built by [Ghost Street](https://ghost.st)
