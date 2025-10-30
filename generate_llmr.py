#!/usr/bin/env python3
"""
LLM-Readable Format Generator
Scans HTML files and generates a compressed .llmr file for AI consumption
"""

import os
import json
import re
from pathlib import Path
from datetime import datetime
from html.parser import HTMLParser
import hashlib


class HTMLMetadataExtractor(HTMLParser):
    """Extract metadata and content from HTML files"""
    
    def __init__(self):
        super().__init__()
        self.title = ""
        self.description = ""
        self.keywords = []
        self.date = ""
        self.tags = []
        self.author = ""
        self.read_time = 0
        self.in_title = False
        self.in_h1 = False
        self.in_time = False
        self.in_article = False
        self.content_text = []
        self.code_blocks = 0
        self.current_tag = None
        
    def handle_starttag(self, tag, attrs):
        attrs_dict = dict(attrs)
        
        if tag == "title":
            self.in_title = True
        elif tag == "h1":
            self.in_h1 = True
        elif tag == "time":
            self.in_time = True
            if "datetime" in attrs_dict:
                self.date = attrs_dict["datetime"]
        elif tag == "article":
            self.in_article = True
        elif tag == "pre" or tag == "code":
            self.code_blocks += 1
            
        # Extract meta tags
        if tag == "meta":
            name = attrs_dict.get("name", "").lower()
            property_name = attrs_dict.get("property", "").lower()
            content = attrs_dict.get("content", "")
            
            if name == "description" or property_name == "og:description":
                self.description = content
            elif name == "keywords":
                self.keywords = [k.strip() for k in content.split(",")]
            elif name == "author" or property_name == "article:author":
                self.author = content
            elif property_name == "article:tag":
                self.tags.append(content)
                
    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False
        elif tag == "h1":
            self.in_h1 = False
        elif tag == "time":
            self.in_time = False
        elif tag == "article":
            self.in_article = False
            
    def handle_data(self, data):
        data = data.strip()
        if not data:
            return
            
        if self.in_title and not self.title:
            self.title = data
        elif self.in_h1 and not self.title:
            self.title = data
        elif self.in_article:
            self.content_text.append(data)
            
        # Extract read time if present
        if "min read" in data.lower():
            match = re.search(r'(\d+)\s*min', data)
            if match:
                self.read_time = int(match.group(1))


def abbreviate_tag(tag):
    """Compress common tags to shorter forms"""
    abbreviations = {
        "productivity": "prod",
        "debugging": "debug",
        "drupal": "drupal",
        "laravel": "laravel",
        "legacy systems": "legacy",
        "web services": "ws",
        "burnout": "burn",
        "teamwork": "team",
        "life": "life",
        "aikido": "aikido",
        "priorities": "prior",
        "enterprise": "ent",
        "ai": "ai",
        "architecture": "arch",
        "godot": "godot",
        "gamedev": "gamedev",
        "mail services": "mail"
    }
    return abbreviations.get(tag.lower(), tag.lower()[:5])


def generate_simple_embedding(text, dimensions=8):
    """
    Generate a simple hash-based embedding for demonstration.
    In production, use sentence-transformers or OpenAI embeddings.
    """
    # Use hash to generate consistent pseudo-random values
    hash_val = int(hashlib.md5(text.encode()).hexdigest(), 16)
    
    # Generate values between -1 and 1
    embedding = []
    for i in range(dimensions):
        val = ((hash_val >> (i * 8)) % 200 - 100) / 100.0
        embedding.append(round(val, 2))
    
    return embedding


def extract_blog_post_data(html_path, base_path):
    """Extract metadata and content from a blog post HTML file"""
    
    with open(html_path, 'r', encoding='utf-8') as f:
        html_content = f.read()
    
    parser = HTMLMetadataExtractor()
    parser.feed(html_content)
    
    # Generate relative URL
    rel_path = os.path.relpath(html_path, base_path)
    url = "/" + rel_path.replace("\\", "/")
    
    # Generate ID from filename
    filename = os.path.basename(html_path)
    post_id = os.path.splitext(filename)[0]
    
    # Combine content for embedding
    content_for_embedding = f"{parser.title} {parser.description} {' '.join(parser.content_text[:100])}"
    
    # Determine if technical content
    technical_keywords = ["code", "debug", "api", "database", "script", "function", "error"]
    is_technical = any(kw in content_for_embedding.lower() for kw in technical_keywords)
    
    post_data = {
        "id": post_id,
        "u": url,
        "d": parser.date[:10] if parser.date else "",
        "tg": [abbreviate_tag(tag) for tag in parser.tags] if parser.tags else [],
        "rt": parser.read_time,
        "tc": 1 if is_technical else 0,
        "cb": parser.code_blocks,
        "emb": generate_simple_embedding(content_for_embedding),
        "sum": parser.description[:100] if parser.description else parser.title
    }
    
    return post_data


def scan_website(base_path):
    """Scan all HTML files in the website directory"""
    
    base_path = Path(base_path)
    blog_posts = []
    
    # Find all HTML files
    html_files = list(base_path.rglob("*.html"))
    
    # Also check if files were provided directly
    if not html_files and base_path.is_file():
        html_files = [base_path]
    
    print(f"Found {len(html_files)} total HTML files")
    
    # Filter for blog posts
    for html_file in html_files:
        filename = html_file.name
        
        # Skip index files and non-blog content
        if filename == "index.html":
            continue
        if "manifesto" in filename.lower():
            continue
        if "laugh" in filename.lower():
            continue
            
        # Include blog posts (they have dates or are in blog folder)
        is_blog_post = (
            re.match(r'\d{4}-\d{2}-\d{2}', filename) or  # Date in filename
            'blog' in str(html_file.parent).lower() or
            any(kw in filename.lower() for kw in ['laravel', 'drupal', 'webservices', 'claudio', 'yaygodot', 'juggling'])
        )
        
        if not is_blog_post:
            continue
            
        try:
            post_data = extract_blog_post_data(html_file, base_path)
            # Include posts even without dates for now
            blog_posts.append(post_data)
            print(f"  ✓ Processed: {filename}")
        except Exception as e:
            print(f"  ✗ Error processing {html_file}: {e}")
    
    # Sort by date (newest first)
    blog_posts.sort(key=lambda x: x["d"], reverse=True)
    
    return blog_posts


def generate_llmr(base_path, output_file="site.llmr", output_dir=None):
    """Generate the complete .llmr file"""
    
    print(f"Scanning website at: {base_path}")
    blog_posts = scan_website(base_path)
    print(f"Found {len(blog_posts)} blog posts")
    
    # Build the compressed structure
    llmr_data = {
        "v": "1.0",
        "ts": int(datetime.now().timestamp()),
        "s": {
            "d": "raphaelreck.com",
            "t": "prof_tech_blog",
            "a": {
                "n": "Raphaël Reck",
                "r": "IT_sys_sw_consultant",
                "exp": ["drupal", "laravel", "sys_arch", "debug", "web_int"]
            },
            "nav": [
                {"l": "Home", "u": "/"},
                {"l": "Blog", "u": "/blog/"},
                {"l": "GitHub", "u": "https://github.com/djassoRaph", "ext": 1},
                {"l": "LinkedIn", "u": "https://linkedin.com/in/raphael-reck-link/", "ext": 1}
            ]
        },
        "p": blog_posts,
        "serv": [
            {"t": "consulting", "d": "Tech audits, team mentoring, roadmap planning"},
            {"t": "dev", "d": "Full-stack web apps, APIs, integrations"},
            {"t": "web_int", "d": "Responsive UI, third-party integration, performance"},
            {"t": "it_support", "d": "Infrastructure monitoring, user support, deployments"},
            {"t": "network", "d": "Wireless, A/V, GPS, surveillance systems"},
            {"t": "sys_arch", "d": "Cloud, on-prem, DevOps, security"}
        ],
        "stats": {
            "total_posts": len(blog_posts),
            "technical_posts": sum(1 for p in blog_posts if p["tc"] == 1),
            "total_code_blocks": sum(p["cb"] for p in blog_posts),
            "avg_read_time": round(sum(p["rt"] for p in blog_posts) / len(blog_posts), 1) if blog_posts else 0
        }
    }
    
    # Write to file (use output_dir if specified, otherwise use base_path)
    if output_dir:
        output_path = os.path.join(output_dir, output_file)
    else:
        output_path = os.path.join(base_path, output_file)
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(llmr_data, f, indent=2, ensure_ascii=False)
    
    print(f"\nGenerated {output_file}")
    print(f"  Total posts: {llmr_data['stats']['total_posts']}")
    print(f"  Technical posts: {llmr_data['stats']['technical_posts']}")
    print(f"  Total code blocks: {llmr_data['stats']['total_code_blocks']}")
    print(f"  Average read time: {llmr_data['stats']['avg_read_time']} min")
    
    # Calculate token savings
    html_files = list(Path(base_path).rglob("*.html"))
    if html_files:
        original_size = sum(os.path.getsize(f) for f in html_files if f.exists())
        llmr_size = os.path.getsize(output_path)
        
        if original_size > 0:
            reduction = ((original_size - llmr_size) / original_size) * 100
            
            print(f"\nCompression Stats:")
            print(f"  Original HTML: {original_size:,} bytes")
            print(f"  LLMR file: {llmr_size:,} bytes")
            print(f"  Reduction: {reduction:.1f}%")
    
    return output_path


if __name__ == "__main__":
    import sys
    
    # Get base path from argument or use current directory
    base_path = sys.argv[1] if len(sys.argv) > 1 else "."
    
    # Generate the .llmr file (output to /home/claude if base_path is read-only)
    output_dir = "/home/claude" if base_path.startswith("/mnt/") else None
    output_file = generate_llmr(base_path, output_dir=output_dir)
    
    print(f"\n % Success! Generated %: {output_file}")
    print("\nTo use this with AI systems:")
    print("1. Upload the .llmr file to your website root, next to your robots.txt")
    print("2. Add to your HTML <head>:")
    print('   <link rel="llm-index" type="application/json" href="/site.llmr">')
    print("\nNote: This uses simple hash-based embeddings.")
    print("For production, consider using sentence-transformers or OpenAI embeddings.")
