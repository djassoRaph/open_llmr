# Introducing LLMR: RSS for the AI Era


---

## The Idea

Jibberish mode but for LLMs when browsing websites.

When AI systems browse the web today, they're forced to parse HTML designed for humans. They wade through navigation bars, CSS classes, footer links, and advertising markup just to extract the actual content.

For my technical blog with 10 posts, an AI must process ~32,000 tokens to understand my content. At GPT-4 pricing, that's $0.96 per query. If 1,000 AI agents visit per month, I'm looking at nearly $1,000 in indirect crawling costs—costs ultimately passed on through API pricing.

There has to be a better way.

## The Thought: LLMR Format

I built a proof-of-concept format called **LLMR** (LLM-Readable) that compresses an entire website into a single, structured JSON file optimized for AI consumption.

Think of it as **RSS for AI systems** or **Jibberish mode for LLMs**

### What Does It Look Like?

```json
{
  "v": "1.0",
  "s": {
    "d": "raphaelreck.com",
    "t": "prof_tech_blog",
    "a": {
      "n": "Raphaël Reck",
      "exp": ["drupal", "laravel", "sys_arch"]
    }
  },
  "p": [
    {
      "id": "juggling-fireballs",
      "u": "/blog/2025-10-08-juggling-fireballs.html",
      "d": "2025-10-08",
      "tg": ["prod", "burn", "solo"],
      "rt": 4,
      "tc": 0,
      "emb": [0.26, 0.77, -0.21, -0.67, ...],
      "sum": "Managing heavy workload when partner experiences burnout"
    }
  ]
}
```

**Key features:**
- **Compressed keys**: `d` = date, `tg` = tags, `tc` = technical content
- **Embeddings**: Pre-computed semantic vectors for instant understanding
- **Metadata rich**: Technical depth, code block count, read time
- **Single file**: Entire site in one HTTP request

### The Results

For my 10-post blog:
- **Traditional HTML**: 32,000 tokens
- **LLMR format**: 1,200 tokens
- **Reduction**: 96%

If your site gets 1,000 AI queries per month:
- Traditional cost: $960/month
- LLMR cost: $36/month
- **Savings: $924/month (96%)**

## How It Works

I wrote a Python script that:

1. **Scans all HTML files** in your website
2. **Extracts metadata** (title, description, tags, dates)
3. **Detects technical content** (code blocks, debugging stories)
4. **Generates embeddings** (semantic fingerprints)
5. **Creates compressed JSON** with abbreviated keys

Add it to your blog publishing workflow:

```python
# After generating RSS/Atom feeds:
subprocess.run(['python3', 'generate_llmr.py', '.'])
```

Done. Your site now speaks AI.

## Why This Matters

We already have standards for different consumers:
- **robots.txt** - for web crawlers
- **sitemap.xml** - for search engines
- **RSS/Atom** - for feed readers
- **manifest.json** - for web apps

We need:
- **site.llmr** - for AI systems

## Real-World Benefits

**For content creators:**
- AI understands your content better
- Lower bandwidth costs
- More accurate AI summaries of your work

**For AI systems:**
- 95%+ token reduction
- Instant semantic understanding
- No HTML parsing overhead

**For users:**
- Faster AI responses about web content
- More accurate information
- Lower API costs

## The Vision: A Consortium

For this to become mainstream, we need:

1. ✅ Working prototype (done!)
2. ⬜ Formal specification document
3. ⬜ AI company adoption (OpenAI, Anthropic, Google)
4. ⬜ CMS plugins (WordPress, Drupal, Ghost)
5. ⬜ Developer evangelism

RSS started with one person (Dave Winer) saying "there should be a better way." This could be the RSS of the AI era.

## Try It Yourself

I've open-sourced the generator:

**GitHub**: [Repository Link](https://github.com/djassoRaph/open_llmr)

**Features:**
- Zero dependencies (pure Python)
- Works with any HTML static site
- Integrates with existing workflows
- Generates hash-based embeddings (production should use sentence-transformers)

**Usage:**
```bash
python3 generate_llmr.py /path/to/your/website
```

Add this to your HTML `<head>`:
```html
<link rel="llm-index" type="application/json" href="/site.llmr">
```

## The Bigger Picture

We're entering an era where AI agents will browse the web as much as humans do. They'll:
- Research topics across hundreds of sites
- Summarize content for users
- Build knowledge graphs
- Answer questions with citations

If we design for this future now—with formats like LLMR—we can:
- Reduce global token consumption
- Lower AI infrastructure costs
- Enable better AI understanding
- Maintain human-readable HTML alongside

This isn't about replacing HTML. It's about **augmenting** it with AI-optimized metadata, the same way we augmented it with RSS for feed readers 20 years ago.

## Call to Action

If you're:
- **A developer** - try the script on your blog
- **An AI company** - consider supporting this format
- **A CMS developer** - build a plugin
- **A standards body** - let's formalize this

Let's build **LLM-First Web Architecture** together.

---

*What are your thoughts? Would you implement this on your site? Hit me up on [LinkedIn](https://linkedin.com/in/raphael-reck-link/) or [GitHub](https://github.com/djassoRaph) to discuss.*

---

**Related Reading:**
- [My blog posts on debugging and development](https://raphaelreck.com/blog/)
- [RSS 2.0 Specification](https://www.rssboard.org/rss-specification)
- [Schema.org structured data](https://schema.org/)

**Downloads:**
- [generate_llmr.py - The generator script]
- [example-site.llmr - Sample output]
- [README.md - Full documentation]
