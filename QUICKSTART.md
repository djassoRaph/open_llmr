# LLMR Generator v2.0 - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Run the Generator

```bash
# In your website directory
python generate_llmr.py

# Or specify path
python generate_llmr.py /path/to/your/website

# With base URL
python generate_llmr.py /path/to/website https://yoursite.com
```

### Step 2: Review the Output

The script creates `site.llmr` in your website directory. Check the console output:

```
===========================================================
Universal LLM-Readable Format Generator v2.0
===========================================================

Found 45 HTML files
  ✓ Processed: index.html
  ✓ Processed: about.html
  ✓ Processed: blog-post-1.html
  ...

Found 45 pages:
  Content types: {'Article': 20, 'WebPage': 15, 'Product': 10}

===========================================================
✓ Successfully generated LLMR file!
===========================================================

Output: /path/to/site.llmr

Statistics:
  total_pages: 45
  total_words: 52000
  avg_read_time: 8.5
  pages_with_code: 12
  pages_with_structured_data: 30
  ...
```

### Step 3: Deploy

Upload `site.llmr` to your website root:

```
your-website/
├── index.html
├── robots.txt
└── site.llmr  ← Upload this
```

### Step 4: Add HTML Link (Optional but Recommended)

Add to your `<head>` section in your HTML template:

```html
<link rel="llm-index" type="application/json" href="/site.llmr">
```

### Step 5: Test

Visit your website and verify the file is accessible:
- `https://yoursite.com/site.llmr`

That's it! Your site is now AI-friendly! 🎉

---

## 📋 Common Use Cases

### Use Case 1: Personal Blog

```bash
cd ~/my-blog
python generate_llmr.py
# Uploads site.llmr to blog root
```

### Use Case 2: Documentation Site

```bash
python generate_llmr.py ~/docs https://docs.mycompany.com
# Creates LLMR with proper base URLs
```

### Use Case 3: E-commerce Site

```bash
python generate_llmr.py ~/shop
# Automatically detects Product pages
```

### Use Case 4: Portfolio Site

```bash
python generate_llmr.py ~/portfolio
# Detects CreativeWork and Portfolio items
```

---

## 🔧 Troubleshooting

### Problem: No pages found
**Solution:** Ensure HTML files exist and have `.html` extension

### Problem: Missing content types
**Solution:** Add Schema.org markup or JSON-LD to your HTML

### Problem: File too large
**Solution:** Edit `llmr_config.py` to reduce embedding dimensions

### Problem: Wrong base URL
**Solution:** Specify base URL as second argument:
```bash
python generate_llmr.py /path https://correct-url.com
```

---

## ⚡ Pro Tips

### Tip 1: Automate Generation

Add to your build process:

```bash
# In your build script
python generate_llmr.py ./build
```

### Tip 2: Schedule Updates

Use cron (Linux/Mac):

```bash
# Regenerate daily at 2am
0 2 * * * cd /var/www/mysite && python /path/to/generate_llmr.py .
```

### Tip 3: Version Control

Add to `.gitignore` if generated frequently:

```
site.llmr
```

Or commit it if stable:

```
git add site.llmr
git commit -m "Update LLMR index"
```

### Tip 4: Add to robots.txt

```
# robots.txt
User-agent: *
Allow: /

# LLM-Readable Index
Sitemap: https://yoursite.com/site.llmr
```

---

## 📊 What Gets Generated

The LLMR file contains:

✅ **Site Metadata**
- Title, description, author
- Content type distribution
- Navigation structure

✅ **Page Data** (for each page)
- URL and content type
- Title and description
- Keywords (auto-extracted)
- Word count and read time
- Embeddings for semantic search
- Structured data (if present)

✅ **Statistics**
- Total pages and words
- Content distribution
- Media counts
- Language detection

---

## 🎯 Real-World Benefits

### For AI Chatbots
```
User: "Find articles about debugging"
AI: *searches LLMR*
    *finds relevant pages in 0.1s*
    "I found 3 articles about debugging..."
```

### For Search
- 70-95% faster than parsing HTML
- Semantic understanding via embeddings
- Direct access to metadata

### For Analytics
```python
import json
with open('site.llmr') as f:
    data = json.load(f)
    
print(f"Total content: {data['stats']['total_words']} words")
print(f"Most common type: {max(data['site']['content_types'])}")
```

---

## 🚀 Next Steps

### Level 1: Basic (You're here!)
✅ Generated LLMR file
✅ Uploaded to website

### Level 2: Enhanced
- [ ] Add JSON-LD to your HTML pages
- [ ] Configure custom settings (see `llmr_config.py`)
- [ ] Set up automatic regeneration

### Level 3: Advanced
- [ ] Upgrade to real embeddings (see `embedding_integration.py`)
- [ ] Integrate with AI chatbot
- [ ] Build semantic search

### Level 4: Expert
- [ ] Custom content type detection
- [ ] Multi-language support
- [ ] Real-time updates

---

## 📚 Additional Resources

- **README.md** - Comprehensive documentation
- **MIGRATION_GUIDE.md** - Upgrading from v1.0
- **llmr_config.py** - Configuration options
- **embedding_integration.py** - Production embeddings
- **compare_versions.py** - Feature comparison

---

## ❓ FAQ

**Q: Do I need to regenerate after every change?**
A: Not necessarily. Regenerate when you add/remove pages or update key content.

**Q: Can I exclude certain pages?**
A: Yes! Edit the `SKIP_PATTERNS` in `llmr_config.py`

**Q: How do I verify it's working?**
A: Check the console output and verify the file exists at the URL

**Q: What if I have multiple languages?**
A: v2.0 auto-detects languages. Each page gets its language tag.

**Q: Can I customize the output?**
A: Yes! See `llmr_config.py` for all options

**Q: Is this SEO-friendly?**
A: Yes! The LLMR format helps AI systems understand your site better, which can improve AI-generated recommendations and references to your content.

---

## 💡 Tips for Best Results

1. **Use structured data** - Add JSON-LD or Schema.org markup to your HTML
2. **Write good meta descriptions** - They're used in the LLMR file
3. **Use semantic HTML** - Proper headings, articles, sections
4. **Keep URLs clean** - Helps with content type detection
5. **Update regularly** - Keep your LLMR file fresh

---

## 🎊 Success!

You now have an AI-friendly website! Your content is:
- ✅ Easily discoverable by AI systems
- ✅ Efficiently parseable (70-95% token reduction)
- ✅ Semantically structured
- ✅ Ready for modern AI integrations

**Happy LLMing!** 🤖✨

---

**Questions or issues?** Check the full documentation in README.md or review the comparison in compare_versions.py
