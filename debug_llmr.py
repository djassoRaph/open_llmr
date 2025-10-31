#!/usr/bin/env python3
"""
LLMR Generator Debug Tool
Helps diagnose issues with website scanning
"""

import sys
import os
from pathlib import Path

# Force UTF-8 on Windows
if sys.platform == "win32":
    os.environ["PYTHONIOENCODING"] = "utf-8"
    import io
    try:
        sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
        sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')
    except:
        pass

def debug_website(base_path):
    """Debug website scanning"""
    
    base_path = Path(base_path)
    
    print("=" * 60)
    print("LLMR Generator Debug Tool")
    print("=" * 60)
    print(f"\nScanning directory: {base_path}")
    print(f"Absolute path: {base_path.absolute()}")
    print(f"Exists: {base_path.exists()}")
    print(f"Is directory: {base_path.is_dir()}")
    
    # Find HTML files
    print("\n" + "=" * 60)
    print("Finding HTML files...")
    print("=" * 60)
    
    html_files = list(base_path.rglob("*.html"))
    
    print(f"\nFound {len(html_files)} HTML files:")
    for i, html_file in enumerate(html_files, 1):
        rel_path = html_file.relative_to(base_path)
        size = html_file.stat().st_size
        print(f"  {i}. {rel_path} ({size:,} bytes)")
    
    if not html_files:
        print("\n[WARNING] No HTML files found!")
        print("\nPossible issues:")
        print("  1. Wrong directory?")
        print("  2. HTML files in subdirectories only?")
        print("  3. File permissions?")
        return
    
    # Test processing first file
    print("\n" + "=" * 60)
    print("Testing first HTML file...")
    print("=" * 60)
    
    test_file = html_files[0]
    print(f"\nTesting: {test_file.name}")
    
    try:
        with open(test_file, 'r', encoding='utf-8') as f:
            content = f.read()
        
        print(f"  [OK] Read file: {len(content)} characters")
        
        # Check for basic HTML structure
        has_html = "<html" in content.lower()
        has_head = "<head" in content.lower()
        has_body = "<body" in content.lower()
        has_title = "<title" in content.lower()
        
        print(f"  [INFO] Has <html>: {has_html}")
        print(f"  [INFO] Has <head>: {has_head}")
        print(f"  [INFO] Has <body>: {has_body}")
        print(f"  [INFO] Has <title>: {has_title}")
        
        # Try to parse
        from html.parser import HTMLParser
        
        class TestParser(HTMLParser):
            def __init__(self):
                super().__init__()
                self.tags = []
                self.title = ""
                
            def handle_starttag(self, tag, attrs):
                self.tags.append(tag)
                
            def handle_data(self, data):
                if self.tags and self.tags[-1] == "title":
                    self.title = data.strip()
        
        parser = TestParser()
        parser.feed(content)
        
        print(f"  [OK] Parsed successfully")
        print(f"  [INFO] Found {len(set(parser.tags))} unique tags")
        print(f"  [INFO] Title: {parser.title[:50] if parser.title else '(none)'}")
        
        # Check for structured data
        has_json_ld = "application/ld+json" in content
        has_schema = "schema.org" in content.lower()
        has_og = 'property="og:' in content
        
        print(f"  [INFO] Has JSON-LD: {has_json_ld}")
        print(f"  [INFO] Has Schema.org: {has_schema}")
        print(f"  [INFO] Has Open Graph: {has_og}")
        
    except Exception as e:
        print(f"  [ERROR] Error processing file: {e}")
        import traceback
        traceback.print_exc()
    
    # Summary
    print("\n" + "=" * 60)
    print("Summary")
    print("=" * 60)
    print(f"\nTotal HTML files: {len(html_files)}")
    print(f"Test file processed: {'Yes' if 'parser' in locals() else 'No'}")
    
    print("\nRecommendations:")
    if len(html_files) == 0:
        print("  - Check that you're in the correct directory")
        print("  - Ensure HTML files have .html extension")
    elif len(html_files) < 5:
        print("  - Website seems small, should work fine")
    else:
        print("  - Website has enough content for LLMR generation")
    
    print("\nNext steps:")
    print("  1. If test passed, run: python generate_llmr.py")
    print("  2. If test failed, check the error messages above")
    print("  3. For Windows, you can also try: python generate_llmr_windows.py")
    
    return html_files


if __name__ == "__main__":
    base_path = sys.argv[1] if len(sys.argv) > 1 else "."
    debug_website(base_path)
