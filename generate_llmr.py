#!/usr/bin/env python3
"""
LLM-Readable Format Generator
Scans most static website and generates a compressed .llmr file for AI consumption
Supports Schema.org, JSON-LD, and intelligent content detection
"""

import os
import json
import re
from pathlib import Path
from datetime import datetime
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse
import hashlib
from collections import Counter
from typing import Dict, List, Any, Optional
from urllib.request import urlopen
from urllib.parse import urlparse


class UniversalHTMLParser(HTMLParser):
    """Extract all relevant data from HTML files including Schema.org and JSON-LD"""
    
    def __init__(self, base_url=""):
        super().__init__()
        self.base_url = base_url
        
        # Basic metadata
        self.title = ""
        self.description = ""
        self.keywords = []
        self.author = ""
        self.language = "en"
        self.canonical_url = ""
        
        # Content tracking
        self.headings = {"h1": [], "h2": [], "h3": [], "h4": [], "h5": [], "h6": []}
        self.paragraphs = []
        self.links = []
        self.images = []
        self.videos = []
        self.code_blocks = []
        self.lists = []
        
        # Structured data
        self.json_ld_data = []
        self.microdata_items = []
        self.rdfa_data = []
        
        # State tracking
        self.current_tag = None
        self.tag_stack = []
        self.in_script = False
        self.in_style = False
        self.current_content = []
        
        # Content analysis
        self.word_count = 0
        self.estimated_read_time = 0
        
    def handle_starttag(self, tag, attrs):
        attrs_dict = dict(attrs)
        self.current_tag = tag
        self.tag_stack.append(tag)
        
        # Extract meta tags
        if tag == "meta":
            self._parse_meta_tag(attrs_dict)
        
        # Extract links
        elif tag == "link":
            self._parse_link_tag(attrs_dict)
        
        # Extract anchors
        elif tag == "a" and "href" in attrs_dict:
            self.links.append({
                "url": attrs_dict["href"],
                "text": "",
                "rel": attrs_dict.get("rel", ""),
                "title": attrs_dict.get("title", "")
            })
        
        # Extract images
        elif tag == "img":
            self.images.append({
                "src": attrs_dict.get("src", ""),
                "alt": attrs_dict.get("alt", ""),
                "title": attrs_dict.get("title", "")
            })
        
        # Extract videos
        elif tag == "video":
            self.videos.append({
                "src": attrs_dict.get("src", ""),
                "poster": attrs_dict.get("poster", "")
            })
        
        # Track scripts for JSON-LD
        elif tag == "script":
            script_type = attrs_dict.get("type", "")
            if script_type == "application/ld+json":
                self.in_script = "json-ld"
            else:
                self.in_script = True
        
        # Track styles
        elif tag == "style":
            self.in_style = True
        
        # Track code blocks
        elif tag in ["pre", "code"]:
            self.code_blocks.append({"tag": tag, "content": ""})
        
        # Extract microdata
        if "itemscope" in attrs_dict:
            self._parse_microdata(tag, attrs_dict)
        
        # Extract RDFa
        if any(k in attrs_dict for k in ["property", "typeof", "vocab"]):
            self._parse_rdfa(tag, attrs_dict)
        
        # Extract language
        if tag == "html" and "lang" in attrs_dict:
            self.language = attrs_dict["lang"]
    
    def handle_endtag(self, tag):
        if self.tag_stack and self.tag_stack[-1] == tag:
            self.tag_stack.pop()
        
        # Handle heading completion
        if tag in self.headings and self.current_content:
            content = " ".join(self.current_content).strip()
            if content:
                self.headings[tag].append(content)
            self.current_content = []
        
        # Handle paragraph completion
        elif tag == "p" and self.current_content:
            content = " ".join(self.current_content).strip()
            if content:
                self.paragraphs.append(content)
                self.word_count += len(content.split())
            self.current_content = []
        
        # Handle link completion
        elif tag == "a" and self.links and self.current_content:
            self.links[-1]["text"] = " ".join(self.current_content).strip()
            self.current_content = []
        
        # Handle code block completion
        elif tag in ["pre", "code"] and self.code_blocks:
            self.code_blocks[-1]["content"] = " ".join(self.current_content).strip()
            self.current_content = []
        
        # Handle script/style end
        elif tag == "script":
            if self.in_script == "json-ld" and self.current_content:
                try:
                    json_data = json.loads(" ".join(self.current_content))
                    self.json_ld_data.append(json_data)
                except json.JSONDecodeError:
                    pass
            self.in_script = False
            self.current_content = []
        
        elif tag == "style":
            self.in_style = False
            self.current_content = []
        
        self.current_tag = self.tag_stack[-1] if self.tag_stack else None
    
    def handle_data(self, data):
        data = data.strip()
        if not data or self.in_style:
            return
        
        # Capture script content (including JSON-LD)
        if self.in_script:
            self.current_content.append(data)
            return
        
        # Extract title
        if self.current_tag == "title" and not self.title:
            self.title = data
        
        # Accumulate content for various tags
        elif self.current_tag in ["h1", "h2", "h3", "h4", "h5", "h6", "p", "a", "li", "pre", "code"]:
            self.current_content.append(data)
    
    def _parse_meta_tag(self, attrs: Dict[str, str]):
        """Parse meta tags for metadata"""
        name = attrs.get("name", "").lower()
        property_name = attrs.get("property", "").lower()
        content = attrs.get("content", "")
        
        if not content:
            return
        
        # Standard meta tags
        if name == "description" or property_name == "og:description":
            self.description = content
        elif name == "keywords":
            self.keywords = [k.strip() for k in content.split(",") if k.strip()]
        elif name == "author" or property_name == "article:author":
            self.author = content
        
        # Open Graph
        elif property_name == "og:title" and not self.title:
            self.title = content
        elif property_name == "og:type":
            if not hasattr(self, 'og_type'):
                self.og_type = content
    
    def _parse_link_tag(self, attrs: Dict[str, str]):
        """Parse link tags"""
        rel = attrs.get("rel", "")
        href = attrs.get("href", "")
        
        if rel == "canonical":
            self.canonical_url = href
    
    def _parse_microdata(self, tag: str, attrs: Dict[str, str]):
        """Parse Schema.org microdata"""
        item = {
            "type": attrs.get("itemtype", ""),
            "properties": {}
        }
        
        if "itemprop" in attrs:
            item["properties"][attrs["itemprop"]] = attrs.get("content", "")
        
        self.microdata_items.append(item)
    
    def _parse_rdfa(self, tag: str, attrs: Dict[str, str]):
        """Parse RDFa attributes"""
        rdfa = {
            "property": attrs.get("property", ""),
            "typeof": attrs.get("typeof", ""),
            "content": attrs.get("content", "")
        }
        self.rdfa_data.append(rdfa)
    
    def calculate_read_time(self):
        """Calculate estimated read time (200 words per minute)"""
        if self.word_count > 0:
            self.estimated_read_time = max(1, round(self.word_count / 200))


class ContentTypeDetector:
    """Detect content type and schema from parsed HTML"""
    
    # Schema.org type mapping
    SCHEMA_TYPES = {
        "Article": ["article", "blog", "post", "news"],
        "BlogPosting": ["blog", "post"],
        "NewsArticle": ["news", "press"],
        "Product": ["product", "item", "shop"],
        "Event": ["event", "conference", "meetup"],
        "Organization": ["about", "company", "organization"],
        "Person": ["profile", "author", "bio"],
        "WebPage": ["page"],
        "HowTo": ["tutorial", "guide", "howto"],
        "FAQPage": ["faq", "questions"],
        "ContactPage": ["contact"],
        "Recipe": ["recipe", "cooking"],
        "VideoObject": ["video", "watch"],
        "Course": ["course", "training", "learn"],
        "JobPosting": ["job", "career", "hiring"],
        "Review": ["review", "rating"]
    }
    
    @staticmethod
    def detect_type(parser: UniversalHTMLParser, url: str) -> str:
        """Detect the content type based on various signals"""
        
        # Check JSON-LD first
        if parser.json_ld_data:
            for ld in parser.json_ld_data:
                if "@type" in ld:
                    schema_type = ld["@type"]
                    if isinstance(schema_type, list):
                        schema_type = schema_type[0]
                    return schema_type
        
        # Check microdata
        for item in parser.microdata_items:
            if item["type"]:
                # Extract Schema.org type
                type_name = item["type"].split("/")[-1]
                return type_name
        
        # Check Open Graph type
        if hasattr(parser, 'og_type'):
            return parser.og_type
        
        # Analyze URL and content
        url_lower = url.lower()
        content_text = " ".join([
            parser.title,
            parser.description,
            " ".join(parser.headings.get("h1", [])),
            " ".join(parser.paragraphs[:3])
        ]).lower()
        
        for schema_type, keywords in ContentTypeDetector.SCHEMA_TYPES.items():
            for keyword in keywords:
                if keyword in url_lower or keyword in content_text:
                    return schema_type
        
        # Default to WebPage
        return "WebPage"
    
    @staticmethod
    def extract_schema_data(parser: UniversalHTMLParser, content_type: str) -> Dict[str, Any]:
        """Extract structured data based on content type"""
        
        data = {
            "type": content_type,
            "title": parser.title,
            "description": parser.description,
            "author": parser.author,
            "language": parser.language
        }
        
        # Extract from JSON-LD
        if parser.json_ld_data:
            for ld in parser.json_ld_data:
                if isinstance(ld, dict):
                    # Add relevant fields
                    for key in ["datePublished", "dateModified", "headline", "articleBody", 
                                "keywords", "author", "publisher", "image"]:
                        if key in ld:
                            data[key] = ld[key]
        
        return data


class KeywordExtractor:
    """Extract and rank keywords from content"""
    
    # Common stop words (simplified)
    STOP_WORDS = set([
        "the", "be", "to", "of", "and", "a", "in", "that", "have", "i",
        "it", "for", "not", "on", "with", "he", "as", "you", "do", "at",
        "this", "but", "his", "by", "from", "they", "we", "say", "her", "she",
        "or", "an", "will", "my", "one", "all", "would", "there", "their", "what",
        "so", "up", "out", "if", "about", "who", "get", "which", "go", "me"
    ])
    
    @staticmethod
    def extract_keywords(parser: UniversalHTMLParser, max_keywords: int = 10) -> List[str]:
        """Extract top keywords from content"""
        
        # Start with explicit keywords
        keywords = set(parser.keywords)
        
        # Combine all text content
        all_text = " ".join([
            parser.title,
            parser.description,
            " ".join(parser.headings.get("h1", [])),
            " ".join(parser.headings.get("h2", [])),
            " ".join(parser.paragraphs)
        ])
        
        # Tokenize and clean
        words = re.findall(r'\b[a-z]{3,}\b', all_text.lower())
        
        # Filter stop words and count
        filtered_words = [w for w in words if w not in KeywordExtractor.STOP_WORDS]
        word_counts = Counter(filtered_words)
        
        # Get top words
        top_words = [word for word, count in word_counts.most_common(max_keywords)]
        
        # Combine with explicit keywords
        keywords.update(top_words[:max_keywords])
        
        return list(keywords)[:max_keywords]


class EmbeddingGenerator:
    """Generate embeddings for content"""
    
    @staticmethod
    def generate_simple_embedding(text: str, dimensions: int = 16) -> List[float]:
        """
        Generate a simple hash-based embedding for demonstration.
        In production, use sentence-transformers, OpenAI embeddings, or similar.
        """
        if not text:
            return [0.0] * dimensions
        
        # Use hash to generate consistent pseudo-random values
        hash_val = int(hashlib.md5(text.encode()).hexdigest(), 16)
        
        # Generate values between -1 and 1
        embedding = []
        for i in range(dimensions):
            val = ((hash_val >> (i * 8)) % 200 - 100) / 100.0
            embedding.append(round(val, 3))
        
        return embedding
    
    @staticmethod
    def generate_content_embedding(parser: UniversalHTMLParser) -> List[float]:
        """Generate embedding from parsed content"""
        
        # Combine important content
        content_parts = [
            parser.title,
            parser.description,
            " ".join(parser.headings.get("h1", [])),
            " ".join(parser.headings.get("h2", [])),
            " ".join(parser.paragraphs[:5])  # First 5 paragraphs
        ]
        
        content_text = " ".join(filter(None, content_parts))
        
        return EmbeddingGenerator.generate_simple_embedding(content_text)


class WebsiteScanner:
    """Scan entire website and extract all pages"""
    
    def __init__(self, base_path: str, base_url: str = ""):
        self.base_path = Path(base_path)
        self.base_url = base_url or str(self.base_path)
        self.pages = []
        self.site_metadata = {
            "title": "",
            "description": "",
            "author": "",
            "nav_links": [],
            "content_types": {}
        }
    
    def scan(self) -> List[Dict[str, Any]]:
        """Scan all HTML files in the website"""
        
        html_files = list(self.base_path.rglob("*.html"))
        
        if not html_files and self.base_path.is_file():
            html_files = [self.base_path]
        
        print(f"Found {len(html_files)} HTML files")
        
        for html_file in html_files:
            try:
                page_data = self._process_page(html_file)
                if page_data:
                    self.pages.append(page_data)
                    print(f"  ✓ Processed: {html_file.name}")
            except Exception as e:
                print(f"  ✗ Error processing {html_file}: {e}")
        
        # Extract site-wide metadata from homepage
        self._extract_site_metadata()
        
        return self.pages
    
    def _process_page(self, html_path: Path) -> Optional[Dict[str, Any]]:
        """Process a single HTML page"""
        
        with open(html_path, 'r', encoding='utf-8') as f:
            html_content = f.read()
        
        # Parse HTML
        parser = UniversalHTMLParser(self.base_url)
        parser.feed(html_content)
        parser.calculate_read_time()
        
        # Generate relative URL
        rel_path = html_path.relative_to(self.base_path)
        url = "/" + str(rel_path).replace("\\", "/")
        
        # Detect content type
        content_type = ContentTypeDetector.detect_type(parser, url)
        
        # Extract structured data
        schema_data = ContentTypeDetector.extract_schema_data(parser, content_type)
        
        # Extract keywords
        keywords = KeywordExtractor.extract_keywords(parser)
        
        # Generate embedding
        embedding = EmbeddingGenerator.generate_content_embedding(parser)
        
        # Build page data
        page_data = {
            "id": html_path.stem,
            "url": url,
            "type": content_type,
            "title": parser.title,
            "description": parser.description[:200] if parser.description else "",
            "keywords": keywords,
            "author": parser.author,
            "language": parser.language,
            "word_count": parser.word_count,
            "read_time": parser.estimated_read_time,
            "headings": {
                k: v for k, v in parser.headings.items() if v
            },
            "links_count": len(parser.links),
            "images_count": len(parser.images),
            "videos_count": len(parser.videos),
            "code_blocks_count": len(parser.code_blocks),
            "has_structured_data": bool(parser.json_ld_data or parser.microdata_items),
            "schema_data": schema_data,
            "embedding": embedding
        }
        
        # Add type-specific data
        if parser.json_ld_data:
            page_data["json_ld"] = parser.json_ld_data
        
        return page_data
    
    def _extract_site_metadata(self):
        """Extract site-wide metadata from homepage or other pages"""
        
        # Find homepage
        homepage = next((p for p in self.pages if p["url"] in ["/", "/index.html"]), None)
        
        if homepage:
            self.site_metadata["title"] = homepage.get("title", "")
            self.site_metadata["description"] = homepage.get("description", "")
            self.site_metadata["author"] = homepage.get("author", "")
        
        # Count content types
        type_counts = Counter(p["type"] for p in self.pages)
        self.site_metadata["content_types"] = dict(type_counts)


class LLMRGenerator:
    """Generate the final .llmr file"""
    
    VERSION = "2.0"
    
    def __init__(self, scanner: WebsiteScanner):
        self.scanner = scanner
    
    def generate(self, output_path: str):
        """Generate the complete .llmr file"""
        
        # Build compressed structure
        llmr_data = {
            "version": self.VERSION,
            "generated": datetime.now().isoformat(),
            "timestamp": int(datetime.now().timestamp()),
            "site": {
                "title": self.scanner.site_metadata.get("title", ""),
                "description": self.scanner.site_metadata.get("description", ""),
                "author": self.scanner.site_metadata.get("author", ""),
                "base_url": self.scanner.base_url,
                "content_types": self.scanner.site_metadata.get("content_types", {}),
                "total_pages": len(self.scanner.pages)
            },
            "pages": self._compress_pages(self.scanner.pages),
            "stats": self._generate_stats(self.scanner.pages)
        }
        
        # Write to file
        with open(output_path, 'w', encoding='utf-8') as f:
            json.dump(llmr_data, f, indent=2, ensure_ascii=False)
        
        return llmr_data
    
    def _compress_pages(self, pages: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """Compress pages – 100% safe for missing keys! 🔥"""
        compressed = []
        for page in pages:
            compressed_page = {
                "id": page.get("id", "unknown"),
                "u": page.get("url", ""),
                "t": page.get("type", "WebPage"),
                "ti": (page.get("title", "") or "")[:100],
                "d": (page.get("description", "") or "")[:200],
                "kw": (page.get("keywords", []) or [])[:10],
                "wc": page.get("word_count", 0),
                "rt": round(page.get("read_time", 0), 1),
                "emb": page.get("embedding", [0.0] * 16)
            }
            
            # Optional fields – safe!
            if author := page.get("author"):
                compressed_page["a"] = author
            if lang := page.get("language"):
                if lang != "en":
                    compressed_page["l"] = lang
            if page.get("has_structured_data"):
                compressed_page["sd"] = 1
            if cb := page.get("code_blocks_count", 0):
                if cb > 0:
                    compressed_page["cb"] = cb
            if h1 := page.get("headings", {}).get("h1"):
                compressed_page["h1"] = h1[0][:100]
            
            compressed.append(compressed_page)
        return compressed
    
    def _generate_stats(self, pages: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Site stats – bulletproof! 💪"""
        if not pages:
            return {"total_pages": 0}
        
        return {
            "total_pages": len(pages),
            "total_words": sum(page.get("word_count", 0) for page in pages),
            "avg_read_time": round(
                sum(page.get("read_time", 0) for page in pages) / len(pages), 1
            ),
            "pages_with_code": sum(1 for p in pages if page.get("code_blocks_count", 0) > 0),
            "pages_with_structured_data": sum(1 for p in pages if page.get("has_structured_data")),
            "total_images": sum(page.get("images_count", 0) for page in pages),
            "total_videos": sum(page.get("videos_count", 0) for page in pages),
            "languages": list(set(page.get("language", "en") for page in pages))
        }


def fetch_remote_html(url: str) -> str:
    """Fetch a single remote HTML page using built-in urllib."""
    try:
        with urlopen(url) as response:
            if "text/html" not in response.headers.get("Content-Type", ""):
                print("/!\\  URL does not return HTML.")
                return ""
            return response.read().decode("utf-8", errors="ignore")
    except Exception as e:
        print(f"/!\\  Failed to fetch {url}: {e}")
        return ""


def main():
    import sys
    
    base_path = sys.argv[1] if len(sys.argv) > 1 else "."
    base_url = sys.argv[2] if len(sys.argv) > 2 else ""

    print("=" * 60)
    print("Universal LLM-Readable Format Generator v2.0")
    print("=" * 60)
    print()

    # === REMOTE MODE: Single page, no local scan ===
    if base_path.startswith(("http://", "https://")):
        domain = urlparse(base_path).netloc.replace("www.", "")
        output_file = f"{domain}.json"
        print(f"Remote mode → Output: {output_file}")

        print(f"Fetching: {base_path}")
        html = fetch_remote_html(base_path)
        if not html:
            print("Failed to fetch HTML.")
            return

        parser = UniversalHTMLParser(base_path)
        parser.feed(html)
        if hasattr(parser, 'calculate_read_time'):
            parser.calculate_read_time()

        # Fallback detectors
        content_type = "WebPage"
        if "blog" in base_path.lower(): content_type = "BlogPosting"
        elif "about" in base_path.lower(): content_type = "AboutPage"

        text = " ".join([parser.title, parser.description] + parser.paragraphs[:5])
        words = re.findall(r'\w+', text.lower())
        stop = {'the','a','an','and','or','but','in','on','at','to','for','of','with'}
        keywords = [w for w in words if w not in stop][:10]

        h = int(hashlib.md5(text.encode()).hexdigest(), 16)
        embedding = [round(((h >> (i*8)) % 200 - 100) / 100.0, 3) for i in range(16)]

        page_data = {
            "id": urlparse(base_path).path.strip("/").replace("/", "_") or "index",
            "url": base_path,
            "type": content_type,
            "title": (parser.title or "")[:100],
            "description": (parser.description or "")[:200],
            "keywords": keywords,
            "author": parser.author or "",
            "language": parser.language if parser.language != "en" else None,
            "word_count": parser.word_count,
            "read_time": round(parser.word_count / 200, 1),
            "embedding": embedding,
            "has_structured_data": bool(parser.json_ld_data),
            "code_blocks_count": len(parser.code_blocks),
            "headings": {k: v for k, v in parser.headings.items() if v},
            "images_count": len(parser.images),
            "videos_count": len(parser.videos),
        }

        # Build LLMR JSON
        llmr_data = {
            "version": "2.0",
            "generated": datetime.now().isoformat(),
            "timestamp": int(datetime.now().timestamp()),
            "site": {
                "title": parser.title or "Untitled",
                "description": parser.description or "",
                "author": parser.author or "",
                "base_url": base_path,
                "content_types": {content_type: 1},
                "total_pages": 1
            },
            "pages": [{
                "id": page_data["id"],
                "u": page_data["url"],
                "t": page_data["type"],
                "ti": page_data["title"],
                "d": page_data["description"],
                "kw": page_data["keywords"],
                "wc": page_data["word_count"],
                "rt": page_data["read_time"],
                "emb": page_data["embedding"],
                **({"a": page_data["author"]} if page_data["author"] else {}),
                **({"l": page_data["language"]} if page_data["language"] else {}),
                **({"sd": 1} if page_data["has_structured_data"] else {}),
                **({"cb": page_data["code_blocks_count"]} if page_data["code_blocks_count"] > 0 else {}),
                **({"h1": page_data["headings"].get("h1", [""])[0][:100]} if page_data["headings"].get("h1") else {})
            }],
            "stats": {
                "total_pages": 1,
                "total_words": page_data["word_count"],
                "avg_read_time": page_data["read_time"],
                "pages_with_code": 1 if page_data["code_blocks_count"] > 0 else 0,
                "pages_with_structured_data": 1 if page_data["has_structured_data"] else 0,
                "total_images": page_data["images_count"],
                "total_videos": page_data["videos_count"],
                "languages": [page_data["language"] or "en"]
            }
        }

        with open(output_file, "w", encoding="utf-8") as f:
            json.dump(llmr_data, f, indent=2, ensure_ascii=False)

        print(f"\nSuccess: {output_file} generated!")
        print(f"Title: {parser.title[:50] or 'N/A'}...")
        print(f"Words: {parser.word_count} | Images: {len(parser.images)}")
        print(f"\nAdd to <head>:")
        print(f'   <link rel="llm-index" type="application/json" href="/{output_file}">')
        print("\n" + "=" * 60)
        return

    # === LOCAL MODE: FULLY PRESERVED ===
    else:
        print("Local mode → Output: site.json")
        scanner = WebsiteScanner(base_path, base_url)
        scanner.scan()

        print(f"\nFound {len(scanner.pages)} pages:")
        print(f"  Content types: {scanner.site_metadata['content_types']}")

        output_file = "site.json"
        generator = LLMRGenerator(scanner)
        llmr_data = generator.generate(output_file)

        print("\n" + "=" * 60)
        print("Successfully generated site.json!")
        print("=" * 60)
        print(f"\nOutput: {output_file}")
        for k, v in llmr_data["stats"].items():
            print(f"  {k}: {v}")

        # Compression stats
        html_files = list(Path(base_path).rglob("*.html"))
        if html_files:
            orig = sum(f.stat().st_size for f in html_files)
            llmr = Path(output_file).stat().st_size
            red = (orig - llmr) / orig * 100
            print(f"\nCompression: {red:.1f}%")

        print("\nAdd to <head>:")
        print('   <link rel="llm-index" type="application/json" href="/site.json">')
        print("\n" + "=" * 60)

if __name__ == "__main__":
    main()